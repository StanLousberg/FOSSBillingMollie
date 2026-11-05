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
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM invoice_event WHERE type = 'paid'"))->toBe(1)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM `transaction` WHERE txn_status = 'paid' AND status = 'processed'"))->toBe(2)
        ->and((int) $check->db->fetchOne("SELECT COUNT(*) FROM `transaction` WHERE txn_status = 'duplicate'"))->toBe(0);
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

test('MariaDB terminal callbacks retain native zero-value outcomes without accounting or activation', function (string $status): void {
    $ctx = mollieMariaContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $events = 0;
    $ctx->di['event_dispatcher']->addListener(Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent::class, static function () use (&$events): void { ++$events; });
    $ctx->status = $status;
    foreach ([1, 2] as $delivery) {
        $tx = mollieWebhook($ctx);
        expect($tx->getStatus())->toBe(Box\Mod\Invoice\Entity\Transaction::STATUS_PROCESSED)
            ->and($tx->getType())->toBe(Payment_Transaction::TXTYPE_UNKNOWN)
            ->and($tx->getTxnStatus())->toBe($status)->and($tx->getAmount())->toBe('0.00')
            ->and($tx->getNote())->toBe('Mollie payment ' . $status . '. No funds received. Invoice remains unpaid.');
    }
    expect(mollieBalance($ctx))->toBe(1000)->and($events)->toBe(0)
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID)
        ->and($ctx->item->getStatus())->not->toBe(Box\Mod\Invoice\Entity\InvoiceItem::STATUS_PENDING_SETUP)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(1);
})->with(['canceled', 'failed', 'expired']);
