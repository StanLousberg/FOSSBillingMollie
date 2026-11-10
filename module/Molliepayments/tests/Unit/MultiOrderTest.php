<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\RecurringEligibility;
use Box\Mod\Order\Entity\Order;

require_once dirname(__DIR__) . '/MultiOrderContext.php';

test('v4 classifies native mixed orders and pays the entire invoice with one first payment', function (array $periods, ?string $adjustment, int $count, int $periodCount): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    foreach ($periods as $period) {
        mollieExtraOrder($ctx, $period);
    }
    if ($adjustment !== null) {
        mollieExtraLine($ctx, $adjustment);
    }
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    $expected = $ctx->invoiceService->getTotalWithTax($ctx->invoice);
    expect($page['recurring_orders'])->toHaveCount($count)->and($page['period_count'])->toBe($periodCount)
        ->and(count($page['one_time_orders']))->toBe(count(array_filter($periods, static fn ($p) => $p === null)))
        ->and(count($page['one_time_lines']))->toBe($adjustment === null ? 0 : 1);
    mollieFirst($ctx);
    $row = mollieAttempt($ctx);
    $scope = json_decode($row['consent_scope'], true);
    expect($scope['scope']['recurring_orders'])->toBe($page['recurring_orders'])
        ->and($row['consent_version'])->toBe('order-collection-v4')->and($row['consent_text'])->toBe($page['consent_text'])
        ->and($ctx->payload['metadata']['scope_hash'])->toBe($page['scope_hash'])
        ->and($ctx->payload['metadata'])->not->toHaveKeys(['order_id', 'order_period', 'recurring_orders'])
        ->and(strlen(json_encode($ctx->payload['metadata'])))->toBeLessThan(1024)
        ->and($ctx->payload['amount']['value'])->toBe(Box\Mod\Molliepayments\Support\Amount::fromHost($expected))
        ->and(count($ctx->requests))->toBe(1);
    $ctx->status = 'paid';
    $ctx->mandateStatus = 'pending';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
    $ledger = $ctx->db->fetchAllAssociative('SELECT * FROM client_balance ORDER BY id');
    $ctx->mandateStatus = 'valid';
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and($ctx->db->fetchAllAssociative('SELECT * FROM client_balance ORDER BY id'))->toBe($ledger)
        ->and(mollieAttempt($ctx)['consent_scope'])->toBe($row['consent_scope']);
})->with([
    'single' => [[], null, 1, 1], 'two monthly' => [['1M'], null, 2, 1],
    'monthly yearly' => [['1Y'], null, 2, 2], 'mixed' => [[null], null, 1, 1],
    'three products' => [[null, '1Y'], null, 2, 2], 'setup' => [[], 'setup', 1, 1],
    'discount' => [[], 'discount', 1, 1],
]);

test('classification deduplicates orders and hashes deterministically despite query order', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $other = mollieExtraOrder($ctx, '1Y');
    $item = clone $ctx->item;
    $ctx->em->persist($item);
    $ctx->em->flush();
    $first = $ctx->payments->onboarding($ctx->invoice->getId());
    $ctx->em->clear();
    $second = $ctx->payments->onboarding($ctx->invoice->getId());
    expect(array_column($first['recurring_orders'], 'order_id'))->toBe([(string) $ctx->order->getId(), (string) $other->getId()])
        ->and($second['scope_hash'])->toBe($first['scope_hash']);
});

test('any invalid recurring component blocks the entire mixed authorization', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $other = mollieExtraOrder($ctx, '1Y');
    if ($case === 'owner') {
        $other->setClientId(999);
    } elseif ($case === 'cancellation') {
        $meta = new Box\Mod\Order\Entity\OrderMeta();
        $meta->setOrder($other)->setName('cancel_at_period_end')->setValue('1');
        $ctx->em->persist($meta);
    } elseif ($case === 'mismatch') {
        $other->setPeriod('2Y');
    } else {
        $other->setStatus($case);
    }
    $ctx->em->flush();
    expect(RecurringEligibility::classify($ctx->em, $ctx->invoice)['invalid_references'])->not->toBeEmpty();
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['owner', 'cancellation', 'mismatch', 'canceled', 'suspended']);

test('mixed authorization rejects changed context before provider payment creation or reuse', function (string $case, bool $reserved): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $other = mollieExtraOrder($ctx, '1Y');
    $notice = $ctx->payments->onboarding($ctx->invoice->getId());
    if ($reserved) {
        mollieFirst($ctx);
    }
    if ($case === 'replace') {
        $replacement = mollieExtraOrder($ctx, '1Y');
        $item = $ctx->em->getRepository(Box\Mod\Invoice\Entity\InvoiceItem::class)->findOneBy(['invoice' => $ctx->invoice, 'relId' => (string) $other->getId()]);
        $item->setRelId((string) $replacement->getId());
    } elseif ($case === 'price') {
        $other->setPrice('88.00');
    } elseif ($case === 'title') {
        $other->setTitle('Changed annual product');
    } elseif ($case === 'line') {
        // Same total, different line allocation is still a different accepted context.
        $ctx->item->setPrice('10.34');
        $item = $ctx->em->getRepository(Box\Mod\Invoice\Entity\InvoiceItem::class)->findOneBy(['invoice' => $ctx->invoice, 'relId' => (string) $other->getId()]);
        $item->setPrice('14.34');
    } else {
        $other->setPeriod('2Y');
        $item = $ctx->em->getRepository(Box\Mod\Invoice\Entity\InvoiceItem::class)->findOneBy(['invoice' => $ctx->invoice, 'relId' => (string) $other->getId()]);
        $item->setPeriod('2Y');
    }
    $ctx->em->flush();
    if ($reserved) {
        $before = mollieAttempt($ctx);
        expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class);
        expect(mollieAttempt($ctx)['request'])->toBe($before['request']);
    } else {
        expect(fn () => $ctx->payments->firstCheckout($ctx->invoice->getId(), true, 'onboarding', $notice['consent_digest']))->toThrow(Payment_Exception::class);
    }
    expect(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe($reserved ? 1 : 0);
})->with(['replace', 'price', 'title', 'line', 'period'])->with([false, true]);

test('v4 local or authenticated provider scope tampering fails before accounting', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $before = mollieAttempt($ctx);
    if ($case === 'provider_hash') {
        $ctx->mutatePayment = static function (array $p): array {
            $p['metadata']['scope_hash'] = str_repeat('0', 64);

            return $p;
        };
    } elseif ($case === 'local_hash') {
        $ctx->payload['metadata']['scope_hash'] = str_repeat('0', 64);
        $ctx->db->update('molliepayments_attempt', ['request' => json_encode($ctx->payload)], ['id' => $before['id']]);
    } elseif ($case === 'text') {
        $ctx->db->update('molliepayments_attempt', ['consent_text' => 'Substituted authorization'], ['id' => $before['id']]);
    } else {
        $ctx->order->setPrice('99.00');
        $ctx->em->flush();
    }
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->invoice->getStatus())->toBe('unpaid')->and($ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with(['provider_hash', 'local_hash', 'text', 'current_order']);

test('mixed invoice mode matrix preserves exact authorization and oneoff has no Customer', function (string $mode, bool $first): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, null);
    mollieExtraOrder($ctx, '1Y');
    mollieSetPaymentMode($ctx, $mode);
    $allowed = $first ? $mode !== 'one_time_only' : $mode !== 'recurring_only';
    $start = static fn () => $first ? mollieFirst($ctx) : $ctx->payments->oneTimeCheckout($ctx->invoice->getId());
    if (!$allowed) {
        expect($start)->toThrow(Payment_Exception::class)->and($ctx->requests)->toBeEmpty();
    } else {
        $start();
        expect($ctx->payload['sequenceType'])->toBe($first ? 'first' : 'oneoff');
        if (!$first) {
            expect(mollieAttempt($ctx)['consent_scope'])->toBeNull()->and(mollieAttempt($ctx)['consent_text'])->toBeNull()
                ->and($ctx->customerRequests)->toBeEmpty();
        }
    }
})->with(['both', 'one_time_only', 'recurring_only'])->with([false, true]);

test('mixed cart validates the exact displayed scope hash and digest', function (string $field): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieExtraOrder($ctx, null);
    $ctx->invoice->setGateway($ctx->gateway);
    $ctx->em->flush();
    $params = mollieCartParams($ctx);
    $params[$field] = str_repeat('0', 64);
    expect(fn () => mollieClientApi($ctx, $params, action: 'cart_checkout')->cart_checkout($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['scope_hash', 'consent_digest']);

test('multiple one-time orders never become recurring authorization', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->order->setPeriod(null);
    $ctx->item->setPeriod(null);
    $ctx->em->flush();
    mollieExtraOrder($ctx, null);
    $classification = RecurringEligibility::classify($ctx->em, $ctx->invoice);
    expect($classification['one_time_orders'])->toHaveCount(2)->and($classification['recurring_orders'])->toBeEmpty()
        ->and(RecurringEligibility::scope($ctx->em, $ctx->invoice))->toBeNull();
    $ctx->payments->checkoutRoute($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['sequenceType'])->toBe('oneoff')->and($ctx->customerRequests)->toBeEmpty();
});

test('a mixed cart creates one first attempt and reuses the Customer without expanding consent', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieExtraOrder($ctx, null);
    $ctx->invoice->setGateway($ctx->gateway);
    $ctx->em->flush();
    mollieResolveCustomer($ctx);
    $customer = mollieCustomerRow($ctx);
    $params = mollieCartParams($ctx);
    $api = mollieClientApi($ctx, $params, action: 'cart_checkout');
    $api->cart_checkout($params);
    $row = mollieAttempt($ctx);
    $api->cart_checkout($params);
    expect(mollieCustomerRow($ctx)['id'])->toBe($customer['id'])
        ->and($ctx->payload['metadata']['consent_source'])->toBe('cart_checkout')
        ->and(mollieAttempt($ctx)['consent_scope'])->toBe($row['consent_scope'])
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
});

test('policy changes preserve accepted v4 evidence while disallowing conflicting checkout', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $before = mollieAttempt($ctx);
    mollieSetPaymentMode($ctx, 'one_time_only');
    expect(fn () => $ctx->payments->resumeCheckout($ctx->invoice->getId()))->toThrow(Payment_Exception::class);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $after = mollieAttempt($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and($after['onboarding_status'])->toBe('ready')
        ->and($after['consent_scope'])->toBe($before['consent_scope'])->and($after['consent_text'])->toBe($before['consent_text']);
});

test('native service activation identifiers do not invalidate the accepted recurring orders', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $ctx->order->setStatus(Order::STATUS_ACTIVE)->setServiceId(123);
    $ctx->em->flush();
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready');
});

test('unknown discount references and contradictory custom periods fail closed', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieExtraLine($ctx, 'discount');
    $discount = $ctx->em->getRepository(Box\Mod\Invoice\Entity\InvoiceItem::class)->findOneBy(['invoice' => $ctx->invoice, 'unit' => 'discount']);
    if ($case === 'reference') {
        $discount->setRelId('999999');
    } elseif ($case === 'period') {
        $discount->setPeriod('1M');
    } else {
        $discount->setPrice('1.00');
    }
    $ctx->em->flush();
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class)->and($ctx->customerRequests)->toBeEmpty();
})->with(['reference', 'period', 'positive_discount']);

test('v4 missing acceptance timestamp blocks unresolved replay before a second POST', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    $row = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating', 'consent_accepted_at' => null], ['id' => $row['id']]);
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class)
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
});

test('invoice-backed cart notice accepts the native hash and rejects foreign owners', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieExtraOrder($ctx, '1Y');
    $api = mollieClientApi($ctx, action: 'consent_notice');
    $notice = $api->consent_notice(['invoice_hash' => $ctx->invoice->getHash()]);
    expect($notice['recurring_orders'])->toHaveCount(2)->and($notice['invoice_id'])->toBe($ctx->invoice->getId());
    $ctx->invoice->setClientId(999);
    $ctx->em->flush();
    expect(fn () => $api->consent_notice(['invoice_hash' => $ctx->invoice->getHash()]))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty();
});

test('onboarding never advertises changed existing consent as resumable or ready', function (bool $paid): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $annual = mollieExtraOrder($ctx, '1Y');
    mollieFirst($ctx);
    if ($paid) {
        $ctx->status = 'paid';
        mollieWebhook($ctx);
    }
    $before = mollieAttempt($ctx);
    $annual->setTitle('Replacement description');
    $ctx->em->flush();
    expect($ctx->payments->onboarding($ctx->invoice->getId()))->toMatchArray([
        'status' => 'manual_review', 'can_pay' => false, 'can_resume' => false,
    ])->and(mollieAttempt($ctx))->toBe($before);
})->with([false, true]);
