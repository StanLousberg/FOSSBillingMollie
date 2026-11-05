<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Transaction;

require_once dirname(__DIR__) . '/PaymentContext.php';

test('pending and authorized payments never create a second payment or settle', function (string $status): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = $status;
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toBeNull();
    $tx = mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID)
        ->and($tx->getTxnStatus())->toBe($status)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and($ctx->requests[1]->getMethod())->toBe('GET');
})->with(['pending', 'authorized']);

test('authenticated terminal statuses release the slot for a new attempt', function (string $status): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $old = mollieAttempt($ctx);
    $ctx->status = $status;
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID)
        ->and(mollieAttempt($ctx)['active_invoice'])->toBeNull()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
    $ctx->status = 'open';
    $ctx->mutatePayment = static function (array $payment): array {
        $payment['id'] = 'tr_retry';

        return $payment;
    };
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(2)
        ->and($ctx->db->fetchOne('SELECT id FROM molliepayments_attempt WHERE active_invoice IS NOT NULL'))->not->toBe($old['id']);
})->with(['failed', 'canceled', 'expired']);

test('a paid webhook settles via native accounting exactly once across distinct delivery rows', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = 'paid';
    $tx = mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and($tx->getTxnId())->toBe('tr_disposable123')->and($tx->getAmount())->toBe('12.34')
        ->and($tx->getStatus())->toBe(Transaction::STATUS_PROCESSED)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2)
        ->and((float) $ctx->db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(0.0);
    $duplicate = mollieWebhook($ctx);
    expect($duplicate->getAmount())->toBe('0.00')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2)
        ->and((int) mollieAttempt($ctx)['settled_transaction_id'])->toBe($tx->getId());
});

test('webhooks reject mismatched authenticated payment fields before accounting', function (string $field): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = 'paid';
    $ctx->mutatePayment = static function (array $payment) use ($field): array {
        match ($field) {
            'amount' => $payment['amount']['value'] = '12.35',
            'currency' => $payment['amount']['currency'] = 'USD',
            'mode' => $payment['mode'] = 'live',
            'invoice' => $payment['metadata']['invoice_id'] = '999',
            'attempt' => $payment['metadata']['attempt_id'] = str_repeat('f', 32),
            'gateway' => $payment['metadata']['gateway_id'] = '999',
            'client' => $payment['metadata']['client_id'] = '999',
            'id' => $payment['id'] = 'tr_foreign',
            'sequence' => $payment['sequenceType'] = 'recurring',
        };

        return $payment;
    };
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'verification failed')
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with(['amount', 'currency', 'mode', 'invoice', 'attempt', 'gateway', 'client', 'id', 'sequence']);

test('untrusted callback identifiers cannot select a foreign attempt', function (string $field): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $data = ['get' => ['invoice_id' => $ctx->invoice->getId(), 'attempt_id' => mollieAttempt($ctx)['id']], 'post' => ['id' => 'tr_disposable123']];
    match ($field) {
        'invalid' => $data['post']['id'] = '../../payments',
        'array' => $data['post']['id'] = ['tr_disposable123'],
        'unknown' => $data['get']['attempt_id'] = str_repeat('f', 32),
        'invoice' => $data['get']['invoice_id'] = 999,
        'id' => $data['post']['id'] = 'tr_other',
    };
    expect(fn () => mollieWebhook($ctx, $data))->toThrow(Payment_Exception::class)
        ->and(count($ctx->requests))->toBe(1);
})->with(['invalid', 'array', 'unknown', 'invoice', 'id']);

test('provider unavailable during a webhook never changes accounting', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = Mollie\Api\Fake\MockResponse::error(503, 'Private provider failure', $ctx->key);
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'unavailable')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID);
});

test('a webhook can recover the provider ID after a lost creation response', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $attempt = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating'], ['id' => $attempt['id']]);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['payment_id'])->toBe('tr_disposable123')
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID);
});

test('money arriving after another payment is retained for reconciliation', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->invoice->setStatus(Invoice::STATUS_PAID);
    $ctx->em->flush();
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'needs reconciliation')
        ->and(mollieAttempt($ctx)['status'])->toBe('paid')
        ->and(mollieAttempt($ctx)['error'])->toBe('settlement_requires_reconciliation')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
});

test('webhook settlement racing checkout creation records one payment outcome', function (): void {
    $ctx = molliePaymentContext();
    $originalResponse = $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($ctx, $originalResponse) {
        $response = $originalResponse($pending);
        $ctx->status = 'paid';
        mollieWebhook($ctx);

        return $response;
    };
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('/invoice/' . $ctx->invoice->getHash())
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('independent webhook workers commit one accounting outcome', function (): void {
    if (!function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for the two-process concurrency test.');
    }
    $database = tempnam(sys_get_temp_dir(), 'mollie-concurrency-');
    $ctx = molliePaymentContext(databasePath: $database);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->close();
    $workers = [];
    try {
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork webhook worker');
            }
            if ($pid === 0) {
                // Reopen independent connections; never share a live PDO socket across forks.
                for ($retry = 0; $retry < 3; ++$retry) {
                    try {
                        $child = molliePaymentContext(databasePath: $database, initialize: false);
                        $child->status = 'paid';
                        mollieWebhook($child);
                        $child->db->close();
                        exit(0);
                    } catch (Throwable) {
                        // SQLite can refuse a read-to-write upgrade. A fresh request
                        // retries from the durable attempt, as the webhook sender does.
                        if (isset($child)) {
                            $child->db->close();
                        }
                    }
                }
                exit(1);
            }
            $workers[] = $pid;
        }
        foreach ($workers as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $check = molliePaymentContext(databasePath: $database, initialize: false);
        expect($check->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
            ->and($check->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2)
            ->and((float) $check->db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(0.0)
            ->and($check->db->fetchOne("SELECT COUNT(*) FROM invoice_event WHERE type = 'paid'"))->toBe(1);
        $check->db->close();
    } finally {
        unlink($database);
    }
});

test('CLI reconciliation recovers a missing provider ID through native claims', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $attempt = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating', 'created_at' => time() - 3601], ['id' => $attempt['id']]);
    $ctx->status = 'paid';
    $id = $ctx->payments->reconcile($attempt['id'], 'tr_disposable123');
    expect($id)->toBeGreaterThan(0)->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and((int) mollieAttempt($ctx)['settled_transaction_id'])->toBe($id);
    $ctx->payments->reconcile($attempt['id'], 'tr_disposable123');
    expect($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('failed settlement rolls back credit, invoice mutation and attempt guard together', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->invoiceService->shouldReceive('markAsPaid')->once()->andThrow(new RuntimeException('Simulated accounting failure'));
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'needs reconciliation')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and($ctx->db->fetchOne('SELECT status FROM invoice WHERE id = ?', [$ctx->invoice->getId()]))->toBe('unpaid')
        ->and(mollieAttempt($ctx)['settled_transaction_id'])->toBeNull()
        ->and(mollieAttempt($ctx)['status'])->toBe('paid');
});
