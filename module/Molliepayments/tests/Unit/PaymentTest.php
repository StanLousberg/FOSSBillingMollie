<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Support\Amount;

require_once dirname(__DIR__) . '/PaymentContext.php';

test('checkout creates an SDK v4 oneoff payment with native URLs and no method restriction', function (string $environment): void {
    $ctx = molliePaymentContext($environment);
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toBe('https://www.mollie.com/checkout/disposable123');
    $attempt = mollieAttempt($ctx);
    expect($ctx->payload['amount'])->toBe(['currency' => 'EUR', 'value' => '12.34'])
        ->and($ctx->payload['description'])->toBe('Payment for invoice INV-00042 [Disposable service]')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')
        ->and($ctx->payload)->not->toHaveKey('method')->not->toHaveKey('testmode')
        ->and($ctx->payload['metadata'])->toBe([
            'attempt_id' => $attempt['id'], 'invoice_id' => (string) $ctx->invoice->getId(),
            'gateway_id' => (string) $ctx->gateway->getId(), 'client_id' => (string) $ctx->client->getId(), 'environment' => $environment,
        ])
        ->and($ctx->payload['redirectUrl'])->toContain('/invoice/payment-return?')
        ->and($ctx->payload['redirectUrl'])->toContain('hash=' . $ctx->invoice->getHash())
        ->and($ctx->payload['webhookUrl'])->toBe(SYSTEM_URL . 'ipn.php?gateway_id=' . $ctx->gateway->getId() . '&invoice_id=' . $ctx->invoice->getId() . '&attempt_id=' . $attempt['id'])
        ->and($ctx->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer ' . $ctx->key)
        ->and($ctx->requests[0]->getHeaderLine('Idempotency-Key'))->toBe($attempt['idempotency_key'])
        ->and(json_encode($attempt))->not->toContain($ctx->key);
})->with(['test', 'live']);

test('valid open payment is fetched and reused without another creation', function (): void {
    $ctx = molliePaymentContext();
    $first = $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toBe($first)
        ->and(count($ctx->requests))->toBe(2)->and($ctx->requests[1]->getMethod())->toBe('GET');
});

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

test('a paid checkout reconciliation uses the host transaction claim and never creates a second payment', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = 'paid';
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('/invoice/' . $ctx->invoice->getHash())
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('invalid invoice states never contact Mollie', function (string $state): void {
    $ctx = molliePaymentContext();
    match ($state) {
        'draft' => $ctx->invoice->setIssued(false),
        'paid' => $ctx->invoice->setStatus(Invoice::STATUS_PAID),
        'canceled' => $ctx->invoice->setStatus(Invoice::STATUS_CANCELED),
        'zero' => $ctx->item->setPrice('0'),
        'negative' => $ctx->item->setPrice('-1'),
        'deposit' => $ctx->item->setType(InvoiceItem::TYPE_DEPOSIT),
    };
    $ctx->em->flush();
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBe([]);
})->with(['draft', 'paid', 'canceled', 'zero', 'negative', 'deposit']);

test('authorization is checked for payment access before contacting Mollie', function (): void {
    $ctx = molliePaymentContext();
    $ctx->invoiceService->shouldReceive('checkInvoiceAuth')->once()->with($ctx->invoice, Box\Mod\Invoice\InvoiceOperation::PAYMENT)
        ->andThrow(new FOSSBilling\Core\Exception\InformationException('Access denied', [], 403));
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))
        ->toThrow(FOSSBilling\Core\Exception\InformationException::class, 'Access denied')
        ->and($ctx->requests)->toBe([]);
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

test('allocated invoice items reduce the payable balance and are not debited twice', function (): void {
    $ctx = molliePaymentContext();
    $ctx->item->setCharged(true);
    $extra = new InvoiceItem();
    $extra->setInvoice($ctx->invoice)->setTitle('Outstanding service')->setType(InvoiceItem::TYPE_CUSTOM)->setPrice('7.66')->setQuantity(1);
    $ctx->em->persist($extra);
    $ctx->em->flush();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['amount']['value'])->toBe('7.66');
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and((float) $ctx->db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(0.0);
});

test('database uniqueness and checkout leases prevent competing active attempts', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $attempt = mollieAttempt($ctx);
    $repo = new AttemptRepository($ctx->db);
    $competitor = $attempt;
    $competitor['id'] = bin2hex(random_bytes(16));
    $competitor['idempotency_key'] = $competitor['id'];
    $competitor['payment_id'] = null;
    expect($repo->reserve($competitor)['id'])->toBe($attempt['id']);
    expect($repo->acquire($attempt['id'], 'worker-one'))->toBeTrue()
        ->and($repo->acquire($attempt['id'], 'worker-two'))->toBeFalse();
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'already checking')
        ->and(count($ctx->requests))->toBe(1);
});

test('lost creation responses replay the same durable request and idempotency key', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $original = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating'], ['id' => $original['id']]);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->requests[1]->getHeaderLine('Idempotency-Key'))->toBe($ctx->requests[0]->getHeaderLine('Idempotency-Key'))
        ->and((string) $ctx->requests[1]->getBody())->toBe((string) $ctx->requests[0]->getBody())
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
});

test('unresolved creations stop before idempotency expiry or credential rotation', function (bool $rotation): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $attempt = mollieAttempt($ctx);
    $changes = ['payment_id' => null, 'status' => 'creating'];
    $changes[$rotation ? 'credential_fingerprint' : 'created_at'] = $rotation ? str_repeat('0', 64) : time() - 2701;
    $ctx->db->update('molliepayments_attempt', $changes, ['id' => $attempt['id']]);
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'Unresolved')
        ->and(count($ctx->requests))->toBe(1);
})->with([true, false]);

test('money comparison uses decimal minor units', function (): void {
    expect(Amount::minor('12.3'))->toBe(1230)->and(Amount::minor('0.30'))->toBe(30)
        ->and(Amount::fromHost(0.1 + 0.2))->toBe('0.30');
    expect(fn () => Amount::minor('1e2'))->toThrow(Payment_Exception::class);
    expect(fn () => Amount::currency('JPY'))->toThrow(Payment_Exception::class);
});

test('failed provider requests are sanitized and leave the durable reservation for retry', function (): void {
    $ctx = molliePaymentContext();
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function () use ($ctx): never {
        throw new RuntimeException('Authorization Bearer ' . $ctx->key . ' private data');
    };
    try {
        $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
        test()->fail('Expected a provider failure');
    } catch (Payment_Exception $error) {
        expect($error->getCode())->toBe(503)->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage())->not->toContain($ctx->key, 'private data', 'Bearer');
        $response = (new FOSSBilling\Core\Http\ApiResponseFactory())->error($error);
        expect($response->getStatusCode())->toBe(503);
    }
    expect(mollieAttempt($ctx)['payment_id'])->toBeNull()->and(mollieAttempt($ctx)['lease'])->toBeNull();
});

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

test('expired checkout URLs and changed balances cannot be reused or replaced while still open', function (bool $expired): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    if ($expired) {
        $ctx->mutatePayment = static function (array $payment): array {
            $payment['expiresAt'] = date(DATE_ATOM, time() - 1);

            return $payment;
        };
    } else {
        $ctx->item->setPrice('22.34');
        $ctx->em->flush();
    }
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and($ctx->requests[1]->getMethod())->toBe('GET');
})->with([true, false]);

test('untrusted checkout hosts cannot become browser redirects', function (): void {
    $ctx = molliePaymentContext();
    $ctx->mutatePayment = static function (array $payment): array {
        $payment['_links']['checkout']['href'] = 'https://attacker.invalid/checkout/x';

        return $payment;
    };
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'invalid checkout URL');
});

test('gateway and environment switches cannot replay an existing attempt', function (bool $environment): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    if ($environment) {
        $ctx->gateway->setTestMode(false);
        $ctx->gateway->setConfig(json_encode(['api_key' => mollieDummyKey('live')]));
    } else {
        $ctx->gateway->setGateway('Custom');
    }
    $ctx->em->flush();
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class)
        ->and(count($ctx->requests))->toBe(1)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with([true, false]);

test('module schema lifecycle creates only its own table and retains audit data on repeat', function (): void {
    $ctx = molliePaymentContext();
    $before = $ctx->db->createSchemaManager()->listTableNames();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->services['molliepayments']->install())->toBeTrue()
        ->and($ctx->services['molliepayments']->update())->toBeTrue()
        ->and($ctx->db->createSchemaManager()->listTableNames())->toBe($before)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
});

test('reentrant checkout during provider creation cannot reserve or send another payment', function (): void {
    $ctx = molliePaymentContext();
    $originalResponse = $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($ctx, $originalResponse) {
        expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))
            ->toThrow(Payment_Exception::class, 'already checking');

        return $originalResponse($pending);
    };
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect(count($ctx->requests))->toBe(1)->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
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

test('gateway rendering uses a browser navigation without exposing credentials', function (): void {
    $ctx = molliePaymentContext();
    $adapter = $ctx->gatewayService->getPaymentAdapter($ctx->gateway, $ctx->invoice);
    $html = $adapter->getHtml(new stdClass(), $ctx->invoice->getId(), false);
    expect($html)->toContain('window.location.assign', 'https://www.mollie.com/checkout/disposable123')
        ->not->toContain($ctx->key, 'method=', 'api.mollie.com');
    expect(fn () => $adapter->getHtml(new stdClass(), $ctx->invoice->getId(), true))->toThrow(Payment_Exception::class);
});

test('invoice-level tax rounding settles without creating a wallet shortfall', function (): void {
    $ctx = molliePaymentContext();
    $ctx->item->setPrice('0.03')->setQuantity(3)->setTaxed(true);
    $ctx->invoice->setTaxrate(21);
    $ctx->em->flush();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['amount']['value'])->toBe('0.11');
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and((float) $ctx->db->fetchOne('SELECT SUM(amount) FROM client_balance'))->toBe(0.0);
});

test('an actual creation timeout retries the identical request without losing its reservation', function (): void {
    $ctx = molliePaymentContext();
    $original = $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = static function ($pending) use ($original): never {
        $original($pending);
        throw new Mollie\Api\Exceptions\RequestTimeoutException($pending, null, 'Simulated timeout after acceptance');
    };
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'unavailable');
    $id = mollieAttempt($ctx)['id'];
    $ctx->responses[Mollie\Api\Http\Requests\CreatePaymentRequest::class] = $original;
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect(mollieAttempt($ctx)['id'])->toBe($id)
        ->and($ctx->requests[1]->getHeaderLine('Idempotency-Key'))->toBe($ctx->requests[0]->getHeaderLine('Idempotency-Key'))
        ->and((string) $ctx->requests[1]->getBody())->toBe((string) $ctx->requests[0]->getBody());
});

test('installation materializes only the module table in an existing host schema', function (): void {
    $ctx = molliePaymentContext();
    $ctx->db->createSchemaManager()->dropTable('molliepayments_attempt');
    $before = $ctx->db->createSchemaManager()->listTableNames();
    $ctx->services['molliepayments']->install();
    $after = $ctx->db->createSchemaManager()->listTableNames();
    expect(array_values(array_diff($after, $before)))->toBe(['molliepayments_attempt'])
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM invoice'))->toBe(1);
});

test('provider return uses the native session-free document before invoice authorization', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    parse_str(parse_url($ctx->payload['redirectUrl'], PHP_URL_QUERY), $params);
    $response = FOSSBilling\Core\Http\PaymentReturn::createResponse(
        Symfony\Component\HttpFoundation\Request::create('/invoice/payment-return', 'GET', $params), $ctx->di['url'],
    );
    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->getCookies())->toBeEmpty()
        ->and($response->getContent())->toContain('window.location.replace(', '/invoice/' . $ctx->invoice->getHash())
        ->not->toContain('restore_token', 'session_id', 'login');
});

test('multiple invoice lines use the full native invoice number without an arbitrary product title', function (): void {
    $ctx = molliePaymentContext();
    $extra = new InvoiceItem();
    $extra->setInvoice($ctx->invoice)->setTitle('Another service')->setType(InvoiceItem::TYPE_CUSTOM)->setPrice('1.00')->setQuantity(1);
    $ctx->em->persist($extra);
    $ctx->em->flush();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['description'])->toBe('Payment for invoice INV-00042');
});
