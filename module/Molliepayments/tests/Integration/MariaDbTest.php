<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Doctrine\DBAL\Tools\DsnParser;

require_once dirname(__DIR__) . '/ClientContext.php';
require_once dirname(__DIR__) . '/MandateContext.php';

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
                    fwrite(STDERR, 'MariaDB worker failed: ' . $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
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
    $ctx->db->update('molliepayments_customer', ['created_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-2700)], ['id' => $row['id']]);
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

require_once dirname(__DIR__) . '/OnboardingContext.php';

test('MariaDB concurrent first checkouts commit one consent and provider identity', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    // Prepare the independently authorized Customer mapping, then race invoice reservations.
    mollieResolveCustomer($ctx);
    $ctx->db->close();
    $job = static function (): void {
        $child = mollieOnboarding(mollieMariaContext(false));
        $post = $child->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
        $child->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($post) {
            // Inspect from a genuinely independent connection before responding to the POST.
            $reader = mollieMariaContext(false);
            $row = mollieAttempt($reader);
            expect($row['consent_version'])->toBe(Box\Mod\Molliepayments\PaymentService::CONSENT_VERSION)
                ->and($row['consent_accepted_at'])->not->toBeNull()->and($row['lease'])->not->toBeNull();
            $reader->db->close();

            return $post($pending);
        };
        try {
            mollieFirst($child);
        } catch (Payment_Exception $error) {
            if (!str_contains($error->getMessage(), 'already checking') && !str_contains($error->getMessage(), 'already in progress')) {
                throw $error;
            }
        }
        $child->db->close();
    };
    mollieMariaWorkers([$job, $job]);
    $check = mollieMariaContext(false);
    expect((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(1)
        ->and(json_decode(mollieAttempt($check)['request'], true)['sequenceType'])->toBe('first')
        ->and((int) $check->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
});

test('MariaDB first payment webhook workers settle once and verify mandate without client session', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    mollieCredit($ctx, '10.00');
    mollieFirst($ctx);
    $ctx->db->close();
    $webhook = static function (): void {
        $child = mollieOnboarding(mollieMariaContext(false));
        unset($child->di['loggedin_client']);
        $child->status = 'paid';
        mollieWebhook($child);
        $child->db->close();
    };
    mollieMariaWorkers([$webhook, $webhook]);
    $check = mollieOnboarding(mollieMariaContext(false));
    expect($check->invoice->getStatus())->toBe('paid')->and(mollieBalance($check))->toBe(1000)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
        ->and(mollieAttempt($check)['onboarding_status'])->toBe('ready');
});

test('MariaDB settlement locks the attempt before invoice refresh can establish a snapshot', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    mollieFirst($ctx);
    $attemptId = mollieAttempt($ctx)['id'];
    $observer = Doctrine\DBAL\DriverManager::getConnection($ctx->connectionParameters);
    $manager = $ctx->em;
    $refreshes = 0;
    $attemptLocked = false;
    $proxy = Mockery::mock($manager);
    $proxy->shouldReceive('refresh')->andReturnUsing(static function ($entity, $mode = Doctrine\DBAL\LockMode::NONE) use ($ctx, $manager, $observer, $attemptId, &$refreshes, &$attemptLocked): void {
        $manager->refresh($entity, $mode);
        if (!$entity instanceof Invoice || !$ctx->db->isTransactionActive() || ++$refreshes !== 2) {
            return;
        }
        // Run a competing mandate-metadata writer precisely between the
        // settlement invoice refresh and the old attempt-lock location.
        $observer->beginTransaction();
        try {
            $observer->fetchOne('SELECT id FROM molliepayments_attempt WHERE id = ? FOR UPDATE NOWAIT', [$attemptId]);
            $observer->update('molliepayments_attempt', ['onboarding_status' => 'pending_mandate'], ['id' => $attemptId]);
            $observer->commit();
        } catch (Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            $attemptLocked = true;
            $observer->rollBack();
        }
    });
    unset($ctx->di['em']);
    $ctx->di['em'] = $proxy;
    try {
        unset($ctx->di['loggedin_client']);
        $ctx->status = 'paid';
        $transaction = mollieWebhook($ctx);
        expect($attemptLocked)->toBeTrue()
            ->and((int) mollieAttempt($ctx)['settled_transaction_id'])->toBe($transaction->getId())
            ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
            ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1);
    } finally {
        $observer->close();
        $ctx->db->close();
    }
});

test('MariaDB first payment lost response reuses durable consent and recovers missing mandate read only', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    $response = $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new RuntimeException('Lost first payment creation response');
    };
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class);
    $original = mollieAttempt($ctx);
    $ctx->db->close();
    $retry = mollieOnboarding(mollieMariaContext(false));
    mollieFirst($retry);
    $retry->status = 'paid';
    $retry->mandateId = null;
    mollieWebhook($retry);
    // No instrument evidence is returned in this fixture: require review until a verified payment link appears.
    expect($retry->invoice->getStatus())->toBe('paid')->and(mollieAttempt($retry)['onboarding_status'])->toBe('manual_review')
        ->and(mollieAttempt($retry)['request'])->toBe($original['request'])
        ->and(mollieAttempt($retry)['consent_accepted_at'])->toBe($original['consent_accepted_at']);
    $retry->mandateId = 'mdt_disposable';
    expect($retry->payments->reverifyOnboarding($retry->invoice->getId())['status'])->toBe('ready')
        ->and((int) $retry->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and((int) $retry->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('MariaDB onboarding migration adds nullable evidence without changing historical records', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    mollieResolveCustomer($ctx);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    mollieCredit($ctx, '10.00');
    $columns = ['consent_version', 'consent_text', 'consent_accepted_at', 'onboarding_status', 'mandate_id', 'mandate_status', 'mandate_verified_at'];
    foreach ($columns as $column) {
        $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt DROP COLUMN ' . $column);
    }
    $tables = ['molliepayments_customer', 'molliepayments_attempt', 'invoice', 'client_balance', 'transaction', 'pay_gateway'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = $ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteIdentifier($table));
    }
    $ctx->services['molliepayments']->update();
    $ctx->services['molliepayments']->update();
    foreach ($before as $table => $rows) {
        $after = $ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteIdentifier($table));
        if ($table === 'molliepayments_attempt') {
            $rows = array_map(static fn ($row) => $row + array_fill_keys($columns, null), $rows);
        }
        expect($after)->toBe($rows);
    }
});

require_once dirname(__DIR__) . '/TransactionContext.php';

test('MariaDB orphan callback workers attach verified invoices and settle once', function (bool $first): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    $first ? mollieFirst($ctx) : $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->close();
    $job = static function (): void {
        $child = mollieOnboarding(mollieMariaContext(false));
        $child->status = 'paid';
        $tx = mollieOrphan($child, false);
        $child->transactionService->preProcessTransaction($tx);
        $child->em->refresh($tx);
        expect($tx->getInvoice()?->getId())->toBe($child->invoice->getId());
        $child->db->close();
    };
    mollieMariaWorkers([$job, $job]);
    $check = mollieOnboarding(mollieMariaContext(false));
    $table = $check->db->quoteSingleIdentifier('transaction');
    expect((int) $check->db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE invoice_id IS NULL"))->toBe(0)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
        ->and($check->invoice->getStatus())->toBe('paid');
})->with([true, false]);

test('MariaDB module DATETIME and native timestamps do not inherit database session offsets', function (string $zone): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($zone);
        $ctx = mollieOnboarding(mollieMariaContext());
        $ctx->db->executeStatement("SET time_zone = '+05:30'");
        mollieFirst($ctx);
        $ctx->status = 'paid';
        $tx = mollieOrphan($ctx);
        $ctx->transactionService->preProcessTransaction($tx);
        $ctx->em->refresh($tx);
        $table = $ctx->db->quoteSingleIdentifier('transaction');
        $raw = $ctx->db->fetchOne("SELECT updated_at FROM {$table} WHERE id = ?", [$tx->getId()]);
        expect($raw)->toBe($tx->getUpdatedAt()->format('Y-m-d H:i:s'))
            ->and($tx->getUpdatedAt()->getTimezone()->getName())->toBe($zone);
        foreach ([mollieAttempt($ctx)['created_at'], mollieCustomerRow($ctx)['created_at'], mollieAttempt($ctx)['consent_accepted_at'], mollieAttempt($ctx)['mandate_verified_at']] as $timestamp) {
            expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
                ->and(Box\Mod\Molliepayments\Support\Timestamps::expired($timestamp, 15))->toBeFalse()
                ->and($timestamp <= Box\Mod\Molliepayments\Support\Timestamps::now())->toBeTrue();
        }
        $ctx->db->close();
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['UTC', 'Europe/Amsterdam']);

test('MariaDB checkout modes apply to native one-time first-payment and cart API paths', function (string $mode, string $path): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    $ctx->invoice->setGateway($ctx->gateway);
    mollieSetPaymentMode($ctx, $mode);
    $checkout = match ($path) {
        'oneoff' => fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()),
        'first' => fn () => mollieFirst($ctx),
        'cart' => fn () => mollieClientApi($ctx, $params = mollieCartParams($ctx), action: 'cart_checkout')->cart_checkout($params),
    };
    if (!Box\Mod\Molliepayments\Support\PaymentMode::resolve($mode)->allows($path === 'oneoff' ? 'oneoff' : 'first')) {
        expect($checkout)->toThrow(Payment_Exception::class)
            ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0)
            ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(0);
    } else {
        $checkout();
        $before = mollieAttempt($ctx);
        $ctx->status = 'paid';
        mollieWebhook($ctx);
        mollieWebhook($ctx);
        expect($ctx->invoice->getStatus())->toBe('paid')
            ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
            ->and(mollieAttempt($ctx)['request'])->toBe($before['request']);
        if ($path !== 'oneoff') {
            expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
                ->and(json_decode($before['request'], true)['metadata']['consent_source'])->toBe($path === 'cart' ? 'cart_checkout' : 'onboarding');
        }
    }
})->with(['both', 'one_time_only', 'recurring_only'])->with(['oneoff', 'first', 'cart']);

test('MariaDB cart API rejects consent and ownership bypasses without reserving payment state', function (string $case): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    $ctx->invoice->setGateway($ctx->gateway);
    $ctx->em->flush();
    $params = mollieCartParams($ctx);
    if ($case === 'csrf') {
        $params['CSRFToken'] = 'wrong';
    } elseif ($case === 'consent') {
        $params['consent'] = false;
    } elseif ($case === 'version') {
        $params['consent_version'] = 'old';
    } elseif ($case === 'owner') {
        // A real FK-valid different owner, rather than bypassing the database constraint.
        $client = clone $ctx->client;
        $client->setEmail('other-disposable@example.invalid');
        $ctx->em->persist($client);
        $ctx->em->flush();
        $ctx->invoice->setClientId($client->getId());
    } elseif ($case === 'mixed') {
        $item = clone $ctx->item;
        $item->setType(Box\Mod\Invoice\Entity\InvoiceItem::TYPE_CUSTOM);
        $ctx->em->persist($item);
    }
    $ctx->em->flush();
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(0)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['csrf', 'consent', 'version', 'owner', 'mixed']);

test('MariaDB concurrent cart consent calls preserve one original authorization source', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    $ctx->invoice->setGateway($ctx->gateway);
    $ctx->em->flush();
    mollieResolveCustomer($ctx);
    $ctx->db->close();
    $job = static function (): void {
        $child = mollieOnboarding(mollieMariaContext(false));
        $params = mollieCartParams($child);
        try {
            mollieClientApi($child, $params, action: 'cart_checkout')->cart_checkout($params);
        } catch (Payment_Exception $error) {
            if (!str_contains($error->getMessage(), 'already checking') && !str_contains($error->getMessage(), 'already in progress')) {
                throw $error;
            }
        }
        $child->db->close();
    };
    mollieMariaWorkers([$job, $job]);
    $check = mollieOnboarding(mollieMariaContext(false));
    $row = mollieAttempt($check);
    expect((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and(json_decode($row['request'], true)['metadata']['consent_source'])->toBe('cart_checkout')
        ->and($row['consent_accepted_at'])->not->toBeNull();
});

test('MariaDB missing mandate IDs recover pending to valid with concurrent native workers', function (): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(mollieMariaContext()));
    $ctx->boundMandate['status'] = 'pending';
    $ctx->discoveryMandates = [$ctx->boundMandate];
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $before = mollieAttempt($ctx);
    expect($before['onboarding_status'])->toBe('pending_mandate');
    $ctx->db->close();
    $worker = static function (): void {
        $child = mollieDiscoverableMandate(mollieOnboarding(mollieMariaContext(false)));
        $child->status = 'paid';
        $child->payments->reverifyOnboarding($child->invoice->getId());
        $child->db->close();
    };
    mollieMariaWorkers([$worker, $worker]);
    $check = mollieOnboarding(mollieMariaContext(false));
    expect(mollieAttempt($check)['onboarding_status'])->toBe('ready')
        ->and(mollieAttempt($check)['mandate_id'])->toBe('mdt_bound')
        ->and(mollieAttempt($check)['request'])->toBe($before['request'])
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
        ->and((int) $check->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
});

test('MariaDB cron retry uses native named locking and preserves readiness during outage', function (): void {
    $ctx = mollieOnboarding(mollieMariaContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mandateStatus = 'pending';
    mollieWebhook($ctx);
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    $other = mollieOnboarding(mollieMariaContext(false));
    FOSSBilling\Core\Doctrine\NamedLock::acquire($other->db, 'molliepayments-mandate-reverification', 0);
    expect($ctx->payments->retryPendingMandates())->toBe(0);
    FOSSBilling\Core\Doctrine\NamedLock::release($other->db, 'molliepayments-mandate-reverification');
    $other->db->close();
    $ctx->mandateStatus = 'valid';
    expect($ctx->payments->retryPendingMandates())->toBe(1);
    $row = mollieAttempt($ctx);
    $ctx->responses[Mollie\Api\Http\Requests\GetMandateRequest::class] = new Mollie\Api\Fake\MockResponse(['status' => 503, 'title' => 'Unavailable'], 503);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBe($row['mandate_verified_at'])
        ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1);
});
