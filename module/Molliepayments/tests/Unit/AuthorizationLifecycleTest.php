<?php

declare(strict_types=1);

use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;

require_once dirname(__DIR__) . '/AuthorizationLifecycleContext.php';

test('paid historical consent survives ordinary changes without financial mutations or new provider POSTs', function (string $change, string $version): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    if ($version !== 'order-collection-v4') {
        mollieLegacyConsent($ctx, $version);
    }
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $evidence = mollieConsentEvidence($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    $posts = mollieProviderPosts($ctx);
    $originalTitle = $ctx->order->getTitle();
    match ($change) {
        'title' => $ctx->order->setTitle('Renamed service'),
        'price' => $ctx->order->setPrice('99.00'),
        'config' => $ctx->order->setConfig(['activated' => true, 'renewal_option' => 'native']),
        'company' => $ctx->services['system']->shouldReceive('getCompany')->andReturn(['name' => 'Renamed company']),
    };
    $ctx->em->flush();
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    expect($page['original_consent_text'])->toBe($evidence['consent_text'])
        ->and($page['original_consent_version'])->toBe($version)
        ->and($page['authorized_orders'][0]['order_title'])->toBe($originalTitle)
        ->and($page['authorization_applicable'])->toBeTrue();
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and(mollieConsentEvidence($ctx))->toBe($evidence)->and(mollieFinancialSnapshot($ctx))->toBe($financial)
        ->and(mollieProviderPosts($ctx))->toBe($posts);
})->with(['title', 'price', 'config', 'company'])->with(['order-collection-v2', 'order-collection-v3', 'order-collection-v4']);

test('native domain activation and renewal pricing do not reinterpret the first consent', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->item->setPeriod('1Y');
    $ctx->order->setPeriod('1Y')->setServiceType('domain')->setConfig(['action' => 'register', 'register_sld' => 'disposable', 'register_tld' => '.invalid']);
    $ctx->em->flush();
    mollieFirst($ctx);
    $evidence = mollieConsentEvidence($ctx);
    $domain = new Product();
    $domain->setType('domain');
    $ctx->services['product']->shouldReceive('findProductById')->andReturn($domain);
    $ctx->services['product']->shouldReceive('getProductRenewalLineConfig')->with($domain, $ctx->order->getConfig())
        ->andReturn(['price' => '65.00', 'quantity' => 1]);
    $ctx->services['product']->shouldReceive('getProductModuleService')->with($domain)->andReturn(new Box\Mod\Servicedomain\Service());
    $ctx->order->setStatus(Order::STATUS_ACTIVE)->setServiceId(42);
    $ctx->em->flush();
    $nativeQuote = $ctx->invoiceService->resolveRenewalLine($ctx->order);
    expect((float) $nativeQuote['price'])->toBe(65.0)->and($nativeQuote['line']['title'])->toContain('renewal');
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['authorization_applicable'])->toBeTrue()
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')->and(mollieConsentEvidence($ctx))->toBe($evidence)
        ->and(mollieFinancialSnapshot($ctx))->toBe($financial);
});

test('partial order changes only exclude that original order and never revoke the other order or mandate', function (string $change, string $reason): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $annual = mollieExtraOrder($ctx, '1Y');
    $annualId = (string) $annual->getId();
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $evidence = mollieConsentEvidence($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    $posts = mollieProviderPosts($ctx);
    match ($change) {
        'canceled', 'all_canceled' => $annual->setStatus(Order::STATUS_CANCELED),
        'scheduled' => mollieScheduleOrderCancellation($ctx, $annual),
        'period' => $annual->setPeriod('2Y'),
        'suspended' => $annual->setStatus(Order::STATUS_SUSPENDED),
        'owner' => $annual->setClientId(999),
        'missing' => $ctx->em->remove($annual),
        'currency' => $annual->setCurrency('USD'),
        'product' => $annual->setProductId(999),
    };
    if ($change === 'all_canceled') {
        $ctx->order->setStatus(Order::STATUS_CANCELED);
    }
    $ctx->em->flush();
    for ($i = 0; $i < 2; ++$i) {
        $page = $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
        expect($page['status'])->toBe('ready')->and($page['authorization_verified'])->toBeTrue()
            ->and($page['authorization_applicable'])->toBe($change !== 'all_canceled')
            ->and(array_column($page['authorized_orders'], 'order_id'))->toBe([(string) $ctx->order->getId(), $annualId])
            ->and(array_column($page['applicable_orders'], 'order_id'))->toBe($change === 'all_canceled' ? [] : [(string) $ctx->order->getId()])
            ->and($page['excluded_orders'][count($page['excluded_orders']) - 1]['exclusion_reason'])->toBe($reason)
            ->and($page['can_pay'])->toBeFalse()->and($page['can_resume'])->toBeFalse()->and($page['can_verify'])->toBeTrue();
    }
    expect(mollieConsentEvidence($ctx))->toBe($evidence)->and(mollieFinancialSnapshot($ctx))->toBe($financial)
        ->and(mollieProviderPosts($ctx))->toBe($posts)->and(mollieAttempt($ctx)['mandate_status'])->toBe('valid');
})->with([
    ['canceled', 'order_unavailable'], ['scheduled', 'cancellation_scheduled'], ['period', 'period_changed'],
    ['all_canceled', 'order_unavailable'], ['suspended', 'order_unavailable'], ['owner', 'owner_changed'],
    ['missing', 'missing_order'], ['currency', 'currency_changed'], ['product', 'product_changed'],
]);

test('new client orders and invoice relations never enter a frozen authorization', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $annual = mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $evidence = mollieConsentEvidence($ctx);
    $order = clone $ctx->order;
    $order->setTitle('Unrelated new order');
    $ctx->em->persist($order);
    $ctx->em->flush();
    // Deliberately introduce an extra relation in the disposable fixture to test non-expansion.
    // Real issued invoice lines are immutable through the native panel services.
    $item = clone $ctx->item;
    $item->setRelId((string) $order->getId());
    $ctx->em->persist($item);
    $ctx->em->flush();
    $page = $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect(array_column($page['authorized_orders'], 'order_id'))->toBe([(string) $ctx->order->getId(), (string) $annual->getId()])
        ->and(array_column($page['applicable_orders'], 'order_id'))->not->toContain((string) $order->getId())
        ->and($page['amount'])->toBe($evidence['amount'])->and(mollieConsentEvidence($ctx))->toBe($evidence);
});

test('known first payments can resume and settle after order lifecycle changes', function (string $change): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $annual = mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $evidence = mollieConsentEvidence($ctx);
    match ($change) {
        'title' => $annual->setTitle('Renamed before settlement'),
        'price' => $annual->setPrice('99.00'),
        'period' => $annual->setPeriod('2Y'),
        'cancel' => $annual->setStatus(Order::STATUS_CANCELED),
        'schedule' => mollieScheduleOrderCancellation($ctx, $annual),
        'config' => $annual->setConfig(['activated' => true]),
    };
    $ctx->em->flush();
    $posts = mollieProviderPosts($ctx);
    expect($ctx->payments->resumeCheckout($ctx->invoice->getId()))->toContain('https://www.mollie.com/checkout/');
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and(mollieConsentEvidence($ctx))->toBe($evidence)->and(mollieProviderPosts($ctx))->toBe($posts)
        ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(1);
})->with(['title', 'price', 'period', 'cancel', 'schedule', 'config']);

test('legacy authorization also separates cancellation from original evidence and financial settlement', function (string $version, bool $beforeSettlement): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    mollieLegacyConsent($ctx, $version);
    $ctx->status = 'paid';
    if (!$beforeSettlement) {
        mollieWebhook($ctx);
    }
    $evidence = mollieConsentEvidence($ctx);
    $ctx->order->setStatus(Order::STATUS_CANCELED)->setPeriod('2Y')->setTitle('Updated legacy order')->setPrice('75.00');
    $ctx->em->flush();
    if ($beforeSettlement) {
        mollieWebhook($ctx);
    }
    $financial = mollieFinancialSnapshot($ctx);
    $page = $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect($page['status'])->toBe('ready')->and($page['applicable_orders'])->toBeEmpty()
        ->and($page['authorization_applicable'])->toBeFalse()->and($page['original_consent_version'])->toBe($version)
        ->and(mollieConsentEvidence($ctx))->toBe($evidence)->and(mollieFinancialSnapshot($ctx))->toBe($financial);
})->with(['order-collection-v2', 'order-collection-v3'])->with([false, true]);

test('tampered historical proof or authenticated metadata requires review without rewriting paid accounting', function (string $field): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $before = mollieAttempt($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    if (str_starts_with($field, 'provider_')) {
        $ctx->mutatePayment = static function (array $payment) use ($field): array {
            match ($field) {
                'provider_metadata' => $payment['metadata']['scope_hash'] = str_repeat('f', 64),
                'provider_customer' => $payment['customerId'] = 'cst_foreign',
                'provider_invoice' => $payment['metadata']['invoice_id'] = '999',
                'provider_gateway' => $payment['metadata']['gateway_id'] = '999',
                'provider_amount' => $payment['amount']['value'] = '0.01',
                'provider_currency' => $payment['amount']['currency'] = 'USD',
                'provider_id' => $payment['id'] = 'tr_foreign',
                'provider_sequence' => $payment['sequenceType'] = 'oneoff',
                'provider_profile' => $payment['profileId'] = 'pfl_foreign',
            };

            return $payment;
        };
    } elseif ($field === 'scope_hash') {
        $ctx->payload['metadata']['scope_hash'] = str_repeat('f', 64);
        $ctx->db->update('molliepayments_attempt', ['request' => json_encode($ctx->payload)], ['id' => $before['id']]);
    } elseif ($field === 'text') {
        $ctx->db->update('molliepayments_attempt', ['consent_text' => $before['consent_text'] . ' Extra order.'], ['id' => $before['id']]);
    } else {
        $ctx->db->update('molliepayments_attempt', ['consent_accepted_at' => $field === 'missing_time' ? null : 'not-a-date'], ['id' => $before['id']]);
    }
    expect(fn () => $ctx->payments->reverifyOnboarding($ctx->invoice->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->payments->onboarding($ctx->invoice->getId())['status'])->toBe('manual_review')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull()->and(mollieFinancialSnapshot($ctx))->toBe($financial);
})->with(['provider_metadata', 'provider_customer', 'provider_invoice', 'provider_gateway', 'provider_amount', 'provider_currency', 'provider_id', 'provider_sequence', 'provider_profile', 'scope_hash', 'text', 'missing_time', 'invalid_time']);

test('legacy wording tampering is rejected without consulting a changed current order', function (string $version): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    mollieLegacyConsent($ctx, $version);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    $ctx->order->setTitle('Legitimately renamed');
    $ctx->em->flush();
    $ctx->db->update('molliepayments_attempt', ['consent_text' => str_replace('I authorize', 'I did not authorize', $row['consent_text'])], ['id' => $row['id']]);
    expect(fn () => $ctx->payments->reverifyOnboarding($ctx->invoice->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->payments->onboarding($ctx->invoice->getId())['status'])->toBe('manual_review')
        ->and(mollieFinancialSnapshot($ctx))->toBe($financial);
})->with(['order-collection-v2', 'order-collection-v3']);

test('historical amounts retain exact two-decimal identity with SQLite integer affinity', function (string $version): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->item->setPrice('12.00');
    $ctx->em->flush();
    mollieFirst($ctx);
    if ($version !== 'order-collection-v4') {
        mollieLegacyConsent($ctx, $version);
    }
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $ctx->order->setPrice('99.00');
    $ctx->em->flush();
    $evidence = mollieConsentEvidence($ctx);
    $financial = mollieFinancialSnapshot($ctx);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId()))->toMatchArray(['status' => 'ready', 'amount' => '12.00'])
        ->and(mollieConsentEvidence($ctx))->toBe($evidence)->and(mollieFinancialSnapshot($ctx))->toBe($financial);
})->with(['order-collection-v2', 'order-collection-v3', 'order-collection-v4']);
