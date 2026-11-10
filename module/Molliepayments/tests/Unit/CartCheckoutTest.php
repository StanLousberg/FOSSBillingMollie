<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\InvoiceItem;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

require_once dirname(__DIR__) . '/ClientContext.php';

function mollieCartContext(): object
{
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->invoice->setGateway($ctx->gateway);
    $ctx->em->flush();

    return $ctx;
}

test('cart consent creates one invoice-backed first payment and persists its fixed source', function (): void {
    $ctx = mollieCartContext();
    $api = mollieClientApi($ctx, action: 'consent_notice');
    $notice = $api->consent_notice(['invoice_id' => $ctx->invoice->getId()]);
    expect($notice['consent_text'])->toContain('Example Hosting', 'actual renewal invoices')
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
    $params = mollieCartParams($ctx) + ['gateway_id' => 999, 'client_id' => 999, 'amount' => '0.01', 'environment' => 'live', 'consent_source' => 'attacker'];
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    // The real dispatcher strips CSRFToken from the action data, retaining the original request.
    unset($params['CSRFToken']);
    expect($api->cart_checkout($params)['checkout_url'])->toContain('https://www.mollie.com/checkout/');
    $before = mollieAttempt($ctx);
    expect($ctx->payload['sequenceType'])->toBe('first')
        ->and($ctx->payload['metadata']['consent_source'])->toBe('cart_checkout')
        ->and($ctx->payload['metadata']['environment'])->toBe('test')
        ->and($ctx->payload['amount']['value'])->toBe('12.34')
        ->and($before['consent_text'])->toContain($notice['consent_text'])
        ->and($before['consent_accepted_at'])->not->toBeNull();
    $api->cart_checkout($params);
    expect(mollieAttempt($ctx)['request'])->toBe($before['request'])
        ->and(mollieAttempt($ctx)['consent_accepted_at'])->toBe($before['consent_accepted_at'])
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and(count(array_filter($ctx->requests, static fn ($request) => $request->getMethod() === 'POST')))->toBe(1);
});

test('cart consent rejects unsafe requests before any provider activity', function (string $case): void {
    $ctx = mollieCartContext();
    $params = mollieCartParams($ctx);
    match ($case) {
        'csrf_missing' => $params['CSRFToken'] = null,
        'csrf_wrong' => $params['CSRFToken'] = 'wrong',
        'csrf_array' => $params['CSRFToken'] = ['disposable-nonce'],
        'missing_consent' => $params['consent'] = null,
        'false_consent' => $params['consent'] = false,
        'array_consent' => $params['consent'] = ['1'],
        'stale_version' => $params['consent_version'] = 'order-collection-v2',
        'array_version' => $params['consent_version'] = ['order-collection-v3'],
        'missing_digest' => $params['consent_digest'] = null,
        'tampered_digest' => $params['consent_digest'] = 'tampered',
        'missing_hash' => $params['invoice_hash'] = null,
        'array_hash' => $params['invoice_hash'] = [$params['invoice_hash']],
        'unknown_hash' => $params['invoice_hash'] = str_repeat('0', 40),
        'url_flag' => $params = ['CSRFToken' => 'disposable-nonce', 'invoice_hash' => $ctx->invoice->getHash(), 'recurring' => '1', 'skip_onboarding' => '1'],
        default => null,
    };
    $api = mollieClientApi($ctx, $params, $case === 'get' ? 'GET' : 'POST', 'cart_checkout');
    if ($case === 'guest') {
        unset($ctx->di['loggedin_client']);
        $api->setIdentity(new FOSSBilling\Core\Identity\Guest());
    } elseif ($case === 'identity') {
        $api->setIdentity(new FOSSBilling\Core\Identity\Guest());
    } elseif ($case === 'owner') {
        $ctx->invoice->setClientId(999);
    } elseif ($case === 'company') {
        $ctx->services['system']->shouldReceive('getCompany')->andReturn(['name' => 'Changed Hosting']);
    } elseif ($case === 'cookie') {
        $ctx->di['request']->cookies->set(FOSSBilling\Core\Http\CookieNames::CSRF, 'wrong');
    } elseif ($case === 'unselected_gateway') {
        $ctx->invoice->setGateway(null);
    } elseif ($case === 'other_gateway') {
        $other = clone $ctx->gateway;
        $other->setGateway('Other');
        $ctx->em->persist($other);
        $ctx->invoice->setGateway($other);
    } elseif ($case === 'duplicate_gateway') {
        $ctx->em->persist(clone $ctx->gateway);
    } elseif ($case === 'disabled') {
        $ctx->gateway->setEnabled(false);
    } elseif ($case === 'allow_single') {
        $ctx->gateway->setAllowSingle(false);
    }
    $ctx->em->flush();
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
})->with(['csrf_missing', 'csrf_wrong', 'csrf_array', 'missing_consent', 'false_consent', 'array_consent', 'stale_version', 'array_version', 'missing_digest', 'tampered_digest', 'missing_hash', 'array_hash', 'unknown_hash', 'url_flag', 'get', 'guest', 'identity', 'owner', 'company', 'cookie', 'unselected_gateway', 'other_gateway', 'duplicate_gateway', 'disabled', 'allow_single']);

test('cart override never grants consent to unsupported invoice or recurring order scope', function (string $case, string $mode): void {
    $ctx = mollieCartContext();
    mollieSetPaymentMode($ctx, $mode);
    $params = mollieCartParams($ctx);
    match ($case) {
        'custom' => $ctx->item->setType(InvoiceItem::TYPE_CUSTOM),
        'deposit' => $ctx->item->setType(InvoiceItem::TYPE_DEPOSIT),
        'one_time' => $ctx->order->setPeriod(null),
        'mismatch' => $ctx->item->setPeriod('1Y'),
        'canceled' => $ctx->order->setStatus('canceled'),
        'suspended' => $ctx->order->setStatus('suspended'),
        'paid' => $ctx->invoice->setStatus('paid'),
        'unissued' => $ctx->invoice->setIssued(false),
        default => null,
    };
    if ($case === 'mixed') {
        $item = clone $ctx->item;
        $item->setType(InvoiceItem::TYPE_CUSTOM);
        $ctx->em->persist($item);
    } elseif ($case === 'multiple') {
        $order = clone $ctx->order;
        $ctx->em->persist($order);
        $ctx->em->flush();
        $item = clone $ctx->item;
        $item->setRelId((string) $order->getId());
        $ctx->em->persist($item);
    }
    $ctx->em->flush();
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['custom', 'deposit', 'one_time', 'mismatch', 'canceled', 'suspended', 'mixed', 'paid', 'unissued'])->with(['both', 'one_time_only', 'recurring_only']);

test('cart consent timeout reuses source evidence request and idempotency key', function (): void {
    $ctx = mollieCartContext();
    $response = $ctx->responses[CreatePaymentRequest::class];
    $ctx->responses[CreatePaymentRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new RuntimeException('Simulated lost response');
    };
    $params = mollieCartParams($ctx);
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class);
    $before = mollieAttempt($ctx);
    $ctx->responses[CreatePaymentRequest::class] = $response;
    $api->cart_checkout($params);
    expect(mollieAttempt($ctx)['request'])->toBe($before['request'])
        ->and(mollieAttempt($ctx)['consent_accepted_at'])->toBe($before['consent_accepted_at'])
        ->and((string) $ctx->requests[0]->getBody())->toBe((string) $ctx->requests[1]->getBody())
        ->and($ctx->requests[0]->getHeaderLine('Idempotency-Key'))->toBe($ctx->requests[1]->getHeaderLine('Idempotency-Key'));
});

test('cart checkout cannot convert existing one-time attempts or rewrite original consent source', function (bool $first): void {
    $ctx = mollieCartContext();
    $first ? mollieFirst($ctx) : $ctx->payments->oneTimeCheckout($ctx->invoice->getId());
    $before = mollieAttempt($ctx);
    $params = mollieCartParams($ctx);
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    if ($first) {
        $api->cart_checkout($params);
    } else {
        expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class, 'conflicting sequence');
        expect($ctx->customerRequests)->toBeEmpty();
    }
    expect(mollieAttempt($ctx)['request'])->toBe($before['request'])
        ->and(mollieAttempt($ctx)['consent_accepted_at'])->toBe($before['consent_accepted_at'])
        ->and(count(array_filter($ctx->requests, static fn ($request) => $request->getMethod() === 'POST')))->toBe(1);
})->with([true, false]);

test('authenticated provider metadata cannot substitute another consent source', function (): void {
    $ctx = mollieCartContext();
    $params = mollieCartParams($ctx);
    mollieClientApi($ctx, $params, action: 'cart_checkout')->cart_checkout($params);
    $ctx->status = 'paid';
    $ctx->mutatePayment = static function (array $payment): array {
        $payment['metadata']['consent_source'] = 'onboarding';

        return $payment;
    };
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->invoice->getStatus())->toBe('unpaid')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
});

test('cart retry preserves source-less historical first-payment requests', function (): void {
    $ctx = mollieCartContext();
    mollieFirst($ctx);
    mollieLegacyConsent($ctx, 'order-collection-v3');
    unset($ctx->payload['metadata']['consent_source']);
    $request = json_encode($ctx->payload, JSON_THROW_ON_ERROR);
    $ctx->db->update('molliepayments_attempt', ['request' => $request], ['id' => mollieAttempt($ctx)['id']]);
    $params = mollieCartParams($ctx);
    mollieClientApi($ctx, $params, action: 'cart_checkout')->cart_checkout($params);
    expect(mollieAttempt($ctx)['request'])->toBe($request);
});

test('guests cannot obtain a client cart consent notice', function (): void {
    $ctx = mollieCartContext();
    $api = mollieClientApi($ctx, action: 'consent_notice');
    unset($ctx->di['loggedin_client']);
    expect(fn () => $api->consent_notice(['invoice_id' => $ctx->invoice->getId()]))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});

test('a mode change during Customer creation cannot create a now-disallowed payment', function (): void {
    $ctx = mollieCartContext();
    $class = Mollie\Api\Http\Requests\CreateCustomerRequest::class;
    $response = $ctx->responses[$class];
    $ctx->responses[$class] = static function ($pending) use ($ctx, $response) {
        $result = $response($pending);
        mollieSetPaymentMode($ctx, 'one_time_only');

        return $result;
    };
    $params = mollieCartParams($ctx);
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
});

test('cart consent stays bound to the company snapshot through invoice authorization', function (): void {
    $ctx = mollieCartContext();
    $params = mollieCartParams($ctx);
    $ctx->invoiceService->shouldReceive('checkInvoiceAuth')->andReturnUsing(static function () use ($ctx): void {
        $ctx->services['system']->shouldReceive('getCompany')->andReturn(['name' => 'Changed Hosting']);
    });
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    expect(fn () => $api->cart_checkout($params))->toThrow(Payment_Exception::class, 'authorization changed')
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});
