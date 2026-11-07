<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\PaymentMode;

require_once dirname(__DIR__) . '/ClientContext.php';

// Each entry is a public/native initiation path; all must enforce saved merchant policy.
test('saved payment mode controls every checkout initiation path', function (string $mode, string $path): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->invoice->setGateway($ctx->gateway);
    mollieSetPaymentMode($ctx, $mode);
    $first = in_array($path, ['first', 'api_first', 'cart'], true);
    $allowed = PaymentMode::resolve($mode)->allows($first ? 'first' : 'oneoff');
    $checkout = function () use ($ctx, $path) {
        return match ($path) {
            'direct' => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()),
            'one_time' => $ctx->payments->oneTimeCheckout($ctx->invoice->getId()),
            'api_one_time' => mollieClientApi($ctx, ['CSRFToken' => 'disposable-nonce'])->checkout(['invoice_id' => $ctx->invoice->getId(), 'payment_mode' => 'both']),
            'first' => mollieFirst($ctx),
            'api_first' => mollieClientApi($ctx, $params = ['CSRFToken' => 'disposable-nonce', 'consent' => '1', 'invoice_id' => $ctx->invoice->getId()] + $ctx->payments->onboarding($ctx->invoice->getId()))->onboarding_start($params),
            'cart' => mollieClientApi($ctx, $params = mollieCartParams($ctx), action: 'cart_checkout')->cart_checkout($params + ['payment_mode' => 'both', 'consent_source' => 'forged']),
        };
    };
    if (!$allowed) {
        expect($checkout)->toThrow(Payment_Exception::class)
            ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
            ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
    } else {
        $checkout();
        expect($ctx->payload['sequenceType'])->toBe($first ? 'first' : 'oneoff');
        if ($first) {
            expect($ctx->payload['metadata']['consent_source'])->toBe($path === 'cart' ? 'cart_checkout' : 'onboarding');
        }
    }
})->with(['both', 'one_time_only', 'recurring_only'])->with(['direct', 'one_time', 'api_one_time', 'first', 'api_first', 'cart']);

test('native gateway routing preserves choice except in one-time-only mode', function (string $mode, bool $owner, bool $eligible): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieSetPaymentMode($ctx, $mode);
    if (!$owner) {
        unset($ctx->di['loggedin_client']);
    }
    if (!$eligible) {
        $ctx->item->setType(Box\Mod\Invoice\Entity\InvoiceItem::TYPE_CUSTOM);
        $ctx->em->flush();
    }
    $adapter = $ctx->gatewayService->getPaymentAdapter($ctx->gateway, $ctx->invoice);
    $run = fn () => $adapter->getHtml(new stdClass(), $ctx->invoice->getId(), false);
    if ($mode === 'recurring_only' && (!$owner || !$eligible)) {
        expect($run)->toThrow(Payment_Exception::class)->and($ctx->requests)->toBeEmpty();
    } else {
        $choice = $mode !== 'one_time_only' && $owner && $eligible;
        expect($run())->toContain($choice ? '/molliepayments/onboarding/' : 'https://www.mollie.com/checkout/')
            ->and(count($ctx->requests))->toBe($choice ? 0 : 1)
            ->and($ctx->customerRequests)->toBeEmpty();
    }
})->with(['both', 'one_time_only', 'recurring_only'])->with([true, false])->with([true, false]);

test('mode changes block conflicting resume or retry but never block verified settlement', function (bool $first): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $first ? mollieFirst($ctx) : $ctx->payments->oneTimeCheckout($ctx->invoice->getId());
    $before = mollieAttempt($ctx);
    mollieSetPaymentMode($ctx, $first ? 'one_time_only' : 'recurring_only');
    expect(fn () => $ctx->payments->resumeCheckout($ctx->invoice->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->payments->onboarding($ctx->invoice->getId())['can_resume'])->toBeFalse();
    expect($first ? fn () => mollieFirst($ctx) : fn () => $ctx->payments->oneTimeCheckout($ctx->invoice->getId()))->toThrow(Payment_Exception::class);
    expect(count($ctx->requests))->toBe(1)->and(mollieAttempt($ctx)['request'])->toBe($before['request']);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid');
    if ($first) {
        expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready');
    }
})->with([true, false]);

test('invalid saved payment modes fail closed including disabled native configuration', function (mixed $mode): void {
    expect(fn () => new Payment_Adapter_MolliePayments(['gateway_id' => 1, 'payment_mode' => $mode]))->toThrow(Payment_Exception::class);
})->with(['', 'invalid', 'BOTH', 1, false, [['both']]]);

test('native gateway configuration defaults to both and saves the selected mode', function (): void {
    [$service] = mollieGatewayHost();
    $gateway = mollieGateway();
    expect(PaymentMode::resolve(null))->toBe(PaymentMode::Both)
        ->and(Payment_Adapter_MolliePayments::getConfig()['form']['payment_mode'][0])->toBe('select');
    foreach (['both', 'one_time_only', 'recurring_only'] as $mode) {
        $service->update($gateway, ['config' => ['payment_mode' => $mode]]);
        expect($service->getPaymentAdapter($gateway)->getParam('payment_mode'))->toBe($mode);
    }
    expect(Payment_Adapter_MolliePayments::getConfig()['supports_subscriptions'])->toBeFalse();
});

test('disallowed payment modes explain the required alternative without starting checkout', function (string $mode, string $message): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieSetPaymentMode($ctx, $mode);
    $run = $mode === 'recurring_only'
        ? fn () => $ctx->payments->oneTimeCheckout($ctx->invoice->getId())
        : fn () => mollieFirst($ctx);
    expect($run)->toThrow(Payment_Exception::class, $message)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with([['recurring_only', 'One-time, mixed and unsupported invoices need another payment gateway'], ['one_time_only', 'Choose Pay once']]);
