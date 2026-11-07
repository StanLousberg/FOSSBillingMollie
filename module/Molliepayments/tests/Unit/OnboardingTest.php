<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\PaymentService;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;

require_once dirname(__DIR__) . '/OnboardingContext.php';

test('first checkout requires explicit owner consent before any provider activity', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    if ($case === 'guest') {
        unset($ctx->di['loggedin_client']);
    } elseif ($case === 'other_owner') {
        $ctx->invoice->setClientId(999);
        $ctx->em->flush();
    }
    expect(fn () => mollieFirst($ctx, $case !== 'declined'))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
})->with(['declined', 'guest', 'other_owner']);

test('first payment commits consent and verified customer identity before its exact SDK POST', function (string $environment): void {
    $ctx = mollieOnboarding(molliePaymentContext($environment));
    $response = $ctx->responses[CreatePaymentRequest::class];
    $ctx->responses[CreatePaymentRequest::class] = static function ($pending) use ($ctx, $response) {
        $row = mollieAttempt($ctx);
        expect($ctx->db->isTransactionActive())->toBeFalse()
            ->and($row['consent_version'])->toBe(PaymentService::CONSENT_VERSION)
            ->and($row['consent_text'])->toBe($ctx->payments->onboarding($ctx->invoice->getId())['consent_text'])
            ->and(Box\Mod\Molliepayments\Support\Timestamps::expired($row['consent_accepted_at'], 5))->toBeFalse()
            ->and($row['onboarding_status'])->toBe('pending_payment')
            ->and($row['lease'])->not->toBeNull()
            ->and(json_decode((string) $pending->createPsrRequest()->getBody(), true))->toEqual(json_decode($row['request'], true));

        return $response($pending);
    };
    expect(mollieFirst($ctx))->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('first')->and($ctx->payload['customerId'])->toBe('cst_disposable')
        ->and($ctx->payload['amount'])->toBe(['currency' => 'EUR', 'value' => '12.34'])
        ->and($ctx->payload)->not->toHaveKey('testmode')->not->toHaveKey('profileId')
        ->and($ctx->payload['metadata']['customer_mapping'])->toBe(mollieCustomerRow($ctx)['id'])
        ->and(mollieAttempt($ctx)['client_id'])->toBe($ctx->client->getId());
    $row = mollieAttempt($ctx);
    mollieFirst($ctx);
    expect(mollieAttempt($ctx)['consent_accepted_at'])->toBe($row['consent_accepted_at'])
        ->and(count($ctx->requests))->toBe(2)->and($ctx->requests[1]->getMethod())->toBe('GET');
})->with(['test', 'live']);

test('conflicting oneoff and first attempts cannot be converted', function (bool $first): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    if ($first) {
        mollieFirst($ctx);
        expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'conflicting sequence');
    } else {
        $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
        expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'conflicting sequence')
            ->and($ctx->customerRequests)->toBeEmpty();
    }
    expect(count($ctx->requests))->toBe(1)->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
})->with([true, false]);

test('paid first payment verifies its exact mandate without browser identity and settles once', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieCredit($ctx, '10.00');
    mollieFirst($ctx);
    unset($ctx->di['loggedin_client']);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieBalance($ctx))->toBe(1000)
        ->and($row['onboarding_status'])->toBe('ready')->and($row['mandate_id'])->toBe('mdt_disposable')
        ->and($row['mandate_status'])->toBe('valid')->and($row['mandate_verified_at'])->not->toBeNull()
        ->and($ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1)
        ->and((string) $ctx->mandateRequests[0]->getUri())->toBe('https://api.mollie.com/v2/customers/cst_disposable/mandates/mdt_disposable')
        ->and($ctx->mandateRequests[0]->getHeaderLine('Authorization'))->toBe('Bearer ' . $ctx->key);
});

test('incomplete mandate never prevents invoice settlement and can recover using GET only', function (string $case, string $expected): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    match ($case) {
        'missing' => $ctx->mandateId = null,
        'pending', 'invalid' => $ctx->mandateStatus = $case,
        'scope' => $ctx->mandateScope = false,
        'unavailable' => $ctx->responses[GetMandateRequest::class] = new MockResponse(['title' => 'Unavailable', 'status' => 503, 'detail' => 'Simulated outage'], 503),
        default => $ctx->mutateMandate = static function (array $mandate) use ($case): array {
            match ($case) {
                'owner' => $mandate['customerId'] = 'cst_foreign',
                'link' => $mandate['_links']['customer']['href'] = 'https://api.mollie.com/v2/customers/cst_foreign',
                'mode' => $mandate['mode'] = 'live',
                'id' => $mandate['id'] = 'mdt_older',
                'method' => $mandate['method'] = 'creditcard',
            };

            return $mandate;
        },
    };
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->toBe($expected)
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
    $ctx->mandateId = 'mdt_disposable';
    $ctx->mandateStatus = 'valid';
    $ctx->mandateScope = true;
    $ctx->mutateMandate = null;
    if ($case === 'unavailable') {
        $ctx->responses[GetMandateRequest::class] = static fn () => MockResponse::ok(mollieMandate($ctx));
    }
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(count(array_filter($ctx->requests, static fn ($request) => $request->getMethod() === 'POST')))->toBe(1)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
})->with([
    ['missing', 'manual_review'], ['pending', 'pending_mandate'], ['invalid', 'mandate_invalid'],
    ['scope', 'manual_review'], ['unavailable', 'pending_mandate'], ['owner', 'manual_review'], ['link', 'manual_review'],
    ['mode', 'manual_review'], ['id', 'manual_review'], ['method', 'manual_review'],
]);

test('nonpaid first payment cannot authorize onboarding or alter accounting', function (string $status): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = $status;
    mollieWebhook($ctx);
    $terminal = in_array($status, ['failed', 'canceled', 'expired'], true);
    expect($ctx->invoice->getStatus())->toBe('unpaid')
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe($terminal ? 'payment_failed' : 'pending_payment')
        ->and($ctx->mandateRequests)->toBeEmpty()->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
    if (!$terminal) {
        expect(mollieFirst($ctx))->toBe($status === 'open' ? 'https://www.mollie.com/checkout/disposable123' : null);
    }
})->with(['open', 'pending', 'authorized', 'failed', 'canceled', 'expired']);

test('first payment rejects mismatched customer profile consent or provider identity before accounting', function (string $field): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    if ($field === 'consent') {
        $ctx->db->update('molliepayments_attempt', ['consent_accepted_at' => null], ['id' => mollieAttempt($ctx)['id']]);
    } else {
        $ctx->mutatePayment = static function (array $payment) use ($field): array {
            match ($field) {
                'customerId' => $payment['customerId'] = 'cst_foreign',
                'profileId' => $payment['profileId'] = 'pfl_foreign',
                'sequenceType' => $payment['sequenceType'] = 'oneoff',
                'mode' => $payment['mode'] = 'live',
                'metadata' => $payment['metadata']['consent_version'] = 'foreign',
                'amount' => $payment['amount']['value'] = '0.01',
            };

            return $payment;
        };
    }
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->invoice->getStatus())->toBe('unpaid')->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with(['customerId', 'profileId', 'sequenceType', 'mode', 'metadata', 'amount', 'consent']);

test('first payment timeout replays immutable consent customer request and idempotency key', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $response = $ctx->responses[CreatePaymentRequest::class];
    $ctx->responses[CreatePaymentRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new RuntimeException('Simulated lost provider response');
    };
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'unavailable');
    $before = mollieAttempt($ctx);
    $ctx->responses[CreatePaymentRequest::class] = $response;
    mollieFirst($ctx);
    expect(mollieAttempt($ctx)['request'])->toBe($before['request'])
        ->and(mollieAttempt($ctx)['consent_accepted_at'])->toBe($before['consent_accepted_at'])
        ->and((string) $ctx->requests[0]->getBody())->toBe((string) $ctx->requests[1]->getBody())
        ->and($ctx->requests[0]->getHeaderLine('Idempotency-Key'))->toBe($ctx->requests[1]->getHeaderLine('Idempotency-Key'));
});

test('late mandate checks cannot replace the payment linked mandate or retain stale readiness', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready');
    $ctx->mandateId = 'mdt_unrelated';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['mandate_id'])->toBe('mdt_disposable')
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull();
});

test('profile and credential changes block first payment reuse and trusted mandate verification', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    if ($case === 'profile') {
        $ctx->profileId = 'pfl_changed';
    } else {
        $ctx->db->update('molliepayments_customer', ['credential_fingerprint' => str_repeat('0', 64)], ['id' => mollieCustomerRow($ctx)['id']]);
    }
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class);
    $ctx->profileId = 'pfl_disposable';
    $ctx->status = 'paid';
    if ($case === 'profile') {
        $ctx->responses[Mollie\Api\Http\Requests\GetCurrentProfileRequest::class] = MockResponse::ok(['resource' => 'profile', 'id' => 'pfl_changed']);
    }
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->not->toBe('ready');
})->with(['profile', 'credential']);

test('additive onboarding schema upgrade preserves old attempts customers secrets and financial rows', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    mollieCredit($ctx, '10.00');
    $old = mollieAttempt($ctx);
    $columns = ['consent_version', 'consent_text', 'consent_accepted_at', 'onboarding_status', 'mandate_id', 'mandate_status', 'mandate_verified_at'];
    foreach ($columns as $column) {
        $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt DROP COLUMN ' . $column);
        unset($old[$column]);
    }
    $before = [];
    foreach (['molliepayments_customer', 'invoice', 'client_balance', 'transaction', 'pay_gateway'] as $table) {
        $before[$table] = $ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteIdentifier($table));
    }
    $ctx->services['molliepayments']->update();
    $ctx->services['molliepayments']->update();
    expect(mollieAttempt($ctx))->toBe($old + array_fill_keys($columns, null));
    foreach ($before as $table => $rows) {
        expect($ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteIdentifier($table)))->toBe($rows);
    }
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('https://www.mollie.com/checkout/');
});

test('payment mandate link is accepted only as an exact authenticated customer association', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mutatePayment = static function (array $payment) use ($case): array {
        $payment['_links']['mandate']['href'] = match ($case) {
            'wrong_customer' => 'https://api.mollie.com/v2/customers/cst_foreign/mandates/mdt_disposable',
            'conflict' => 'https://api.mollie.com/v2/customers/cst_disposable/mandates/mdt_older',
            default => 'https://api.mollie.com/v2/customers/cst_disposable/mandates/mdt_disposable',
        };
        if ($case === 'link_only') {
            $payment['mandateId'] = null;
        }

        return $payment;
    };
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe(in_array($case, ['link_only', 'consistent'], true) ? 'ready' : 'manual_review');
})->with(['link_only', 'consistent', 'wrong_customer', 'conflict']);

test('a customer present only mandate never authorizes future collection', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mutateMandate = static function (array $mandate): array {
        $mandate['scopes'] = ['customer-present'];

        return $mandate;
    };
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review')->and($ctx->invoice->getStatus())->toBe('paid');
});

test('first payment safe replay refuses an expired creation window and rotated credentials', function (bool $rotation): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $row = mollieAttempt($ctx);
    $changes = ['payment_id' => null, 'status' => 'creating'];
    $changes[$rotation ? 'credential_fingerprint' : 'created_at'] = $rotation ? str_repeat('0', 64) : Box\Mod\Molliepayments\Support\Timestamps::now(-2701);
    $ctx->db->update('molliepayments_attempt', $changes, ['id' => $row['id']]);
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'Unresolved')
        ->and(count($ctx->requests))->toBe(1)->and(mollieAttempt($ctx)['id'])->toBe($row['id']);
})->with([true, false]);

test('out of order first payment responses preserve paid state and verified onboarding', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $ctx->status = 'pending';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['status'])->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('paid first checkout recovery releases its creation lease before mandate verification', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    expect(mollieFirst($ctx))->toContain('/invoice/')
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and(mollieAttempt($ctx)['lease'])->toBeNull();
});

test('invoice reassignment or credential rotation during customer resolution prevents first payment POST', function (bool $rotation): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $response = $ctx->responses[Mollie\Api\Http\Requests\CreateCustomerRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreateCustomerRequest::class] = static function ($pending) use ($ctx, $response, $rotation) {
        $result = $response($pending);
        if ($rotation) {
            $ctx->db->update('pay_gateway', ['config' => json_encode(['test_api_key' => mollieDummyKey('test')])], ['id' => $ctx->gateway->getId()]);
        } else {
            $ctx->db->update('invoice', ['client_id' => 999], ['id' => $ctx->invoice->getId()]);
        }

        return $result;
    };
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
})->with([true, false]);

test('native payment event is dispatched after financial commit and before mandate network operations', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $eventCount = 0;
    $ctx->di['event_dispatcher']->addListener(Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent::class, static function () use ($ctx, &$eventCount): void {
        expect($ctx->db->isTransactionActive())->toBeFalse()->and(mollieAttempt($ctx)['settled_transaction_id'])->not->toBeNull();
        ++$eventCount;
    });
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->responses[GetMandateRequest::class] = static function () use (&$eventCount): MockResponse {
        expect($eventCount)->toBe(1);

        return new MockResponse(['status' => 503, 'title' => 'Unavailable'], 503);
    };
    mollieWebhook($ctx);
    mollieWebhook($ctx);
    expect($eventCount)->toBe(1)->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
});

test('ordinary authorized invoice checkout never resolves the browser client identity service', function (): void {
    $ctx = molliePaymentContext();
    // Native hash-authorized payment access may have no authenticated browser client.
    $ctx->di['loggedin_client'] = static function (): never {
        throw new RuntimeException('Oneoff checkout must not demand onboarding authentication');
    };
    expect($ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(0);
});

test('existing v2 authorization resumes and settles without rewriting its evidence or request', function (bool $unresolved): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $row = mollieAttempt($ctx);
    $legacy = 'Order #' . $ctx->order->getId() . ' (' . $ctx->order->getTitle() . '), ' . (new FOSSBilling\Core\Period('1M'))->getTitle() . '. I authorize future automatic collection through Mollie only for renewal invoices of the recurring order identified above. Amounts will be based on the actual invoices. I will complete this first payment using the account or card to authorize. Automatic collection is not enabled by this release.';
    $ctx->payload['metadata']['consent_version'] = 'order-collection-v2';
    $request = json_encode($ctx->payload, JSON_THROW_ON_ERROR);
    $ctx->db->update('molliepayments_attempt', ['consent_version' => 'order-collection-v2', 'consent_text' => $legacy, 'request' => $request], ['id' => $row['id']]);
    if ($unresolved) {
        $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating'], ['id' => $row['id']]);
        expect(mollieFirst($ctx))->toContain('https://www.mollie.com/checkout/');
        expect((string) $ctx->requests[1]->getBody())->toBe($request)
            ->and($ctx->requests[1]->getHeaderLine('Idempotency-Key'))->toBe($ctx->requests[0]->getHeaderLine('Idempotency-Key'));
    } else {
        expect($ctx->payments->resumeCheckout($ctx->invoice->getId()))->toContain('https://www.mollie.com/checkout/');
    }
    $ctx->status = 'paid';
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready');
    $after = mollieAttempt($ctx);
    expect($after['consent_version'])->toBe('order-collection-v2')->and($after['consent_text'])->toBe($legacy)
        ->and($after['consent_accepted_at'])->toBe($row['consent_accepted_at'])->and($after['request'])->toBe($request)
        ->and(count(array_filter($ctx->requests, static fn ($request) => $request->getMethod() === 'POST')))->toBe($unresolved ? 2 : 1);
})->with([false, true]);
