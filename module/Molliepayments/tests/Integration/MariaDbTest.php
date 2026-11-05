<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Doctrine\DBAL\Tools\DsnParser;

require_once dirname(__DIR__) . '/PaymentContext.php';

beforeEach(function (): void {
    if (!getenv('MOLLIEPAYMENTS_MYSQL_URL')) {
        test()->markTestSkipped('Set MOLLIEPAYMENTS_MYSQL_URL to the disposable molliepayments_test database.');
    }
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('MariaDB concurrency coverage requires pcntl.');
    }
});

function mollieMariaContext(bool $initialize = true, ?int $invoiceId = null): object
{
    $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('MOLLIEPAYMENTS_MYSQL_URL'));
    if (($parameters['dbname'] ?? '') !== 'molliepayments_test' || ($parameters['driver'] ?? '') !== 'pdo_mysql') {
        throw new RuntimeException('Refusing to reset any database except the disposable molliepayments_test database.');
    }
    $ctx = molliePaymentContext(initialize: $initialize, connectionParameters: $parameters);
    if ($invoiceId !== null) {
        $ctx->invoice = $ctx->em->find(Invoice::class, $invoiceId);
        $ctx->item = $ctx->em->getRepository(Box\Mod\Invoice\Entity\InvoiceItem::class)->findOneBy(['invoice' => $ctx->invoice]);
        $attempt = $ctx->db->fetchAssociative('SELECT * FROM molliepayments_attempt WHERE invoice_id = ? ORDER BY created_at DESC LIMIT 1', [$invoiceId]);
        $ctx->payload = $attempt ? json_decode($attempt['request'], true) : null;
    }
    $ctx->paymentId = 'tr_invoice' . $ctx->invoice->getId();

    return $ctx;
}

/** Independent processes, EntityManagers and connections, released by one barrier. */
function mollieMariaWorkers(array $jobs): void
{
    $barrier = tempnam(sys_get_temp_dir(), 'mp-barrier-');
    unlink($barrier);
    $pids = [];
    try {
        foreach ($jobs as $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork MariaDB worker');
            }
            if ($pid === 0) {
                $deadline = microtime(true) + 10;
                while (!is_file($barrier) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    $job();
                    exit(0);
                } catch (Throwable $error) {
                    // Fixtures contain dummy credentials only. Keep even test
                    // failures free of request objects and exception traces.
                    fwrite(STDERR, 'MariaDB worker failed: ' . $error::class . "\n");
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
    } finally {
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
}

test('MariaDB simultaneous checkouts preserve one reservation and provider creation', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->db->close();
    $checkout = static function (): void {
        $child = mollieMariaContext(false);
        try {
            $child->payments->checkout($child->invoice->getId(), $child->gateway->getId());
        } catch (Payment_Exception $error) {
            if (!str_contains($error->getMessage(), 'already checking')) {
                throw $error;
            }
        }
        $child->db->close();
    };
    mollieMariaWorkers([$checkout, $checkout]);
    $check = mollieMariaContext(false);
    expect((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'molliepayments_reserve'"))->toBe(0)
        ->and(mollieBalance($check))->toBe(1000);
});

test('MariaDB duplicate webhook workers settle once and leave existing credit untouched', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->close();
    $webhook = static function (): void {
        $child = mollieMariaContext(false);
        $child->status = 'paid';
        mollieWebhook($child);
        $child->db->close();
    };
    mollieMariaWorkers([$webhook, $webhook]);
    $check = mollieMariaContext(false);
    expect($check->invoice->getStatus())->toBe('paid')->and(mollieBalance($check))->toBe(1000)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1);
});

test('MariaDB competing invoices leave native credit untouched', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $other = new Invoice();
    $other->setClientId($ctx->client->getId())->setCurrency('EUR')->setIssued(true)->setHash(bin2hex(random_bytes(20)))->setNr('43')->setSerie('INV-')->setTaxrate(0);
    $ctx->em->persist($other);
    $ctx->em->flush();
    $item = new Box\Mod\Invoice\Entity\InvoiceItem();
    $item->setInvoice($other)->setTitle('Other invoice')->setType('custom')->setQuantity(1)->setPrice('12.34');
    $ctx->em->persist($item);
    $ctx->em->flush();
    $ids = [$ctx->invoice->getId(), $other->getId()];
    $ctx->db->close();
    $jobs = array_map(static fn (int $id) => static function () use ($id): void {
        $child = mollieMariaContext(false, $id);
        $child->payments->checkout($id, $child->gateway->getId());
        $child->db->close();
    }, $ids);
    mollieMariaWorkers($jobs);
    $check = mollieMariaContext(false);
    expect((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(2)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type LIKE 'molliepayments_%'"))->toBe(0)
        ->and(mollieBalance($check))->toBe(1000);
});

test('MariaDB concurrent cancellation does not alter credit', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->close();
    $cancel = static function (): void {
        $child = mollieMariaContext(false);
        $child->status = 'canceled';
        mollieWebhook($child);
        $child->db->close();
    };
    mollieMariaWorkers([$cancel, $cancel]);
    $check = mollieMariaContext(false);
    expect(mollieBalance($check))->toBe(1000)->and($check->invoice->getStatus())->toBe('unpaid')
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'molliepayments_release'"))->toBe(0)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM `transaction` WHERE status = 'error'"))->toBe(0);
});

test('MariaDB checkout and paid webhook converge on one accounting outcome', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->close();
    mollieMariaWorkers([
        static function (): void {
            $child = mollieMariaContext(false);
            $child->status = 'paid';
            try {
                $child->payments->checkout($child->invoice->getId(), $child->gateway->getId());
            } catch (Payment_Exception $error) {
                if (!str_contains($error->getMessage(), 'not payable')) {
                    throw $error;
                }
            }
            $child->db->close();
        },
        static function (): void {
            $child = mollieMariaContext(false);
            $child->status = 'paid';
            mollieWebhook($child);
            $child->db->close();
        },
    ]);
    $check = mollieMariaContext(false);
    expect($check->invoice->getStatus())->toBe('paid')->and(mollieBalance($check))->toBe(1000)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1);
});

test('MariaDB timeout recovery leaves credit untouched and the durable creation identity', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $response = $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new Mollie\Api\Exceptions\RequestTimeoutException($pending, null, 'Simulated timeout');
    };
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'unavailable');
    $id = mollieAttempt($ctx)['id'];
    $ctx->db->close();
    $retry = mollieMariaContext(false);
    $retry->payments->checkout($retry->invoice->getId(), $retry->gateway->getId());
    expect(mollieAttempt($retry)['id'])->toBe($id)->and(mollieBalance($retry))->toBe(1000)
        ->and((int) $retry->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'molliepayments_reserve'"))->toBe(0);
});

test('MariaDB database deadlock errors roll back settlement and allow a fresh verified retry', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->invoiceService->shouldReceive('markAsPaid')->once()->andReturnUsing(static function () use ($ctx): void {
        $ctx->db->executeStatement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Injected database deadlock'");
    });
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'needs reconciliation')
        ->and(mollieBalance($ctx))->toBe(1000);
    $ctx->db->close();
    $retry = mollieMariaContext(false);
    $retry->status = 'paid';
    mollieWebhook($retry);
    expect($retry->invoice->getStatus())->toBe('paid')->and(mollieBalance($retry))->toBe(1000);
});

test('MariaDB upgrades retain legacy wallet columns and history without releasing funds', function (): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt ADD credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00');
    $ctx->db->executeStatement("ALTER TABLE molliepayments_attempt ADD credit_state VARCHAR(16) NOT NULL DEFAULT 'none'");
    $ctx->db->update('molliepayments_attempt', ['credit_amount' => '10.00', 'credit_state' => 'reserved'], ['id' => mollieAttempt($ctx)['id']]);
    $ctx->clientService->addFunds($ctx->client, '-10.00', 'Historical fixture', ['type' => 'molliepayments_reserve', 'rel_id' => $ctx->invoice->getId()]);
    $before = mollieAttempt($ctx);
    $ctx->services['molliepayments']->update();
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'Legacy account-credit attempt')
        ->and(mollieAttempt($ctx))->toBe($before)->and(mollieBalance($ctx))->toBe(0)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

require_once dirname(__DIR__) . '/CustomerContext.php';

test('MariaDB simultaneous customer workers commit one mapping and one provider creation', function (): void {
    $ctx = mollieCustomers(mollieMariaContext());
    $ctx->db->executeStatement('CREATE TABLE IF NOT EXISTS mp_customer_test_calls (mapping_id VARCHAR(32) NOT NULL)');
    $ctx->db->executeStatement('DELETE FROM mp_customer_test_calls');
    $ctx->db->close();
    $job = static function (): void {
        $child = mollieCustomers(mollieMariaContext(false));
        $response = $child->customerResponses[Mollie\Api\Http\Requests\CreateCustomerRequest::class];
        $child->customerResponses[Mollie\Api\Http\Requests\CreateCustomerRequest::class] = static function ($pending) use ($child, $response) {
            // Separate connection proves identity is committed before network IO.
            $observer = Doctrine\DBAL\DriverManager::getConnection($child->connectionParameters);
            $row = $observer->fetchAssociative('SELECT * FROM molliepayments_customer');
            expect($row['idempotency_key'])->toBe($pending->createPsrRequest()->getHeaderLine('Idempotency-Key'));
            $observer->insert('mp_customer_test_calls', ['mapping_id' => $row['id']]);
            $observer->close();
            usleep(100000);

            return $response($pending);
        };
        try {
            mollieResolveCustomer($child);
        } catch (Payment_Exception $error) {
            if (!str_contains($error->getMessage(), 'already in progress')) {
                throw $error;
            }
        }
        $child->db->close();
    };
    mollieMariaWorkers([$job, $job]);
    $check = mollieCustomers(mollieMariaContext(false));
    expect((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(1)
        ->and((int) $check->db->fetchOne('SELECT COUNT(*) FROM mp_customer_test_calls'))->toBe(1)
        ->and(mollieResolveCustomer($check))->toBe('cst_disposable');
});

test('MariaDB customer lost response recovers with the committed idempotency identity', function (): void {
    $ctx = mollieCustomers(mollieMariaContext());
    $response = $ctx->customerResponses[Mollie\Api\Http\Requests\CreateCustomerRequest::class];
    $ctx->customerResponses[Mollie\Api\Http\Requests\CreateCustomerRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new Mollie\Api\Exceptions\RequestTimeoutException($pending, null, 'Simulated lost response');
    };
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->db->close();
    $retry = mollieCustomers(mollieMariaContext(false));
    expect(mollieResolveCustomer($retry))->toBe('cst_disposable')
        ->and(mollieCustomerRow($retry)['idempotency_key'])->toBe($row['idempotency_key'])
        ->and(mollieCustomerRow($retry)['request'])->toBe($row['request']);
});

test('MariaDB customer upgrades preserve mapping and payment records', function (): void {
    $ctx = mollieCustomers(mollieMariaContext());
    mollieResolveCustomer($ctx);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $customer = mollieCustomerRow($ctx);
    $attempt = mollieAttempt($ctx);
    $ctx->db->executeStatement('ALTER TABLE molliepayments_customer ADD legacy_marker VARCHAR(32) DEFAULT NULL');
    $ctx->services['molliepayments']->update();
    expect(mollieAttempt($ctx))->toBe($attempt)
        ->and(mollieCustomerRow($ctx))->toBe($customer + ['legacy_marker' => null]);
});

test('MariaDB customer repeated verification and expired creation recovery never replace identity', function (): void {
    $ctx = mollieCustomers(mollieMariaContext());
    $ctx->customerResponses[Mollie\Api\Http\Requests\CreateCustomerRequest::class] = new Mollie\Api\Fake\MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->db->update('molliepayments_customer', ['created_at' => time() - 2700], ['id' => $row['id']]);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    expect($ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_disposable'))->toBe('cst_disposable')
        ->and(mollieResolveCustomer($ctx, false, true))->toBe('cst_disposable')
        ->and(mollieResolveCustomer($ctx, false, true))->toBe('cst_disposable')
        ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
});

test('MariaDB customer precondition failures preserve mapping diagnostics and ownership', function (): void {
    $ctx = mollieCustomers(mollieMariaContext());
    mollieResolveCustomer($ctx);
    $row = mollieCustomerRow($ctx);
    expect(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_other'))->toThrow(Payment_Exception::class, 'cannot replace')
        ->and(mollieCustomerRow($ctx))->toBe($row);
    $ctx->profileId = 'pfl_othermerchant';
    expect(fn () => mollieResolveCustomer($ctx, false, true))->toThrow(Payment_Exception::class, 'profile context changed')
        ->and(mollieCustomerRow($ctx)['status'])->toBe('blocked');
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'profile context changed');
});
