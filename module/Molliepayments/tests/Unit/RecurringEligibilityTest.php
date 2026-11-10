<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Order\Entity\Order;

require_once dirname(__DIR__) . '/OnboardingContext.php';

function mollieIneligible(object $ctx, string $case): void
{
    match ($case) {
        'custom' => $ctx->item->setType(InvoiceItem::TYPE_CUSTOM),
        'deposit' => $ctx->item->setType(InvoiceItem::TYPE_DEPOSIT),
        'one_time' => $ctx->order->setPeriod(null),
        'period_mismatch' => $ctx->item->setPeriod('1Y'),
        'invalid_period' => $ctx->order->setPeriod('0M'),
        'canceled' => $ctx->order->setStatus(Order::STATUS_CANCELED),
        'foreign_order' => $ctx->order->setClientId(999),
        'missing_order' => $ctx->item->setRelId('999'),
        default => null,
    };
    if (in_array($case, ['mixed', 'multiple'], true)) {
        $item = clone $ctx->item;
        if ($case === 'mixed') {
            $item->setType(InvoiceItem::TYPE_CUSTOM)->setPeriod(null);
        } else {
            $order = clone $ctx->order;
            $ctx->em->persist($order);
            $ctx->em->flush();
            $item->setRelId((string) $order->getId());
        }
        $ctx->em->persist($item);
    }
    $ctx->em->flush();
}

test('ineligible invoice rejects onboarding page and direct API before provider activity', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieIneligible($ctx, $case);
    expect(fn () => $ctx->payments->onboarding($ctx->invoice->getId()))->toThrow(Payment_Exception::class, 'eligible recurring order');
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
})->with(['custom', 'deposit', 'one_time', 'period_mismatch', 'invalid_period', 'canceled', 'foreign_order', 'missing_order', 'mixed']);

test('native recurring checkout routes before provider requests or reservation', function (string $status): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->order->setStatus($status);
    $ctx->em->flush();
    $adapter = $ctx->gatewayService->getPaymentAdapter($ctx->gateway, $ctx->invoice);
    expect($adapter->getHtml(new stdClass(), $ctx->invoice->getId(), false))->toContain('/molliepayments/onboarding/' . $ctx->invoice->getId())
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    expect($page)->toMatchArray(['order_id' => (string) $ctx->order->getId(), 'order_title' => 'Disposable recurring service', 'period' => '1M', 'amount' => '12.34', 'currency' => 'EUR', 'can_pay' => true]);
})->with([Order::STATUS_PENDING_SETUP, Order::STATUS_ACTIVE]);

test('hash only recurring invoice checkout remains oneoff without consent', function (bool $nativeGuest): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    unset($ctx->di['loggedin_client']);
    if ($nativeGuest) {
        $ctx->di['loggedin_client'] = static function () {
            throw new FOSSBilling\Core\Security\AuthenticationRequiredException('client');
        };
    }
    $url = $ctx->payments->checkoutRoute($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($url)->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')->and($ctx->customerRequests)->toBeEmpty()
        ->and(mollieAttempt($ctx)['consent_text'])->toBeNull();
    if (!$nativeGuest) {
        expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'owner authentication');
    }
})->with([false, true]);

test('one time order uses native checkout without authorization', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->order->setPeriod(null);
    $ctx->item->setPeriod(null);
    $ctx->em->flush();
    expect($ctx->payments->checkoutRoute($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')->and($ctx->customerRequests)->toBeEmpty();
});

test('unresolved creation cannot replay after an invoice item is reassigned', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    mollieFirst($ctx);
    $attempt = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['payment_id' => null, 'status' => 'creating'], ['id' => $attempt['id']]);
    expect($attempt['consent_text'])->toBe($page['consent_text'])
        ->and(json_decode($attempt['consent_scope'], true)['scope']['recurring_orders'][0])->toMatchArray(['order_id' => (string) $ctx->order->getId(), 'period' => '1M'])
        ->and($ctx->payload['metadata']['scope_hash'])->toBe($page['scope_hash']);
    $order = clone $ctx->order;
    $ctx->em->persist($order);
    $ctx->em->flush();
    $ctx->item->setRelId((string) $order->getId());
    $ctx->em->flush();
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'authorization changed')
        ->and(count($ctx->requests))->toBe(1)->and(mollieAttempt($ctx)['request'])->toBe($attempt['request']);
});

test('paid pending and blocked onboarding never offer another payment', function (string $state): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->db->update('molliepayments_attempt', ['onboarding_status' => $state], ['id' => mollieAttempt($ctx)['id']]);
    if (in_array($state, ['ready', 'pending_mandate', 'mandate_invalid'], true)) {
        $ctx->status = 'paid';
        mollieWebhook($ctx);
    }
    expect($ctx->payments->onboarding($ctx->invoice->getId())['can_pay'])->toBeFalse();
})->with(['pending_payment', 'ready', 'pending_mandate', 'mandate_invalid', 'manual_review']);

test('resume existing checkout never creates or converts a payment', function (bool $first): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $first ? mollieFirst($ctx) : $ctx->payments->oneTimeCheckout($ctx->invoice->getId());
    $before = mollieAttempt($ctx);
    expect($ctx->payments->onboarding($ctx->invoice->getId()))->toMatchArray(['can_pay' => false, 'can_resume' => true]);
    expect($ctx->payments->resumeCheckout($ctx->invoice->getId()))->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->requests[1]->getMethod())->toBe('GET')
        ->and(mollieAttempt($ctx)['request'])->toBe($before['request']);
    $ctx->status = 'paid';
    $ctx->payments->resumeCheckout($ctx->invoice->getId());
    expect($ctx->invoice->getStatus())->toBe('paid')->and($ctx->payments->onboarding($ctx->invoice->getId())['can_resume'])->toBeFalse()
        ->and(count(array_filter($ctx->requests, static fn ($request) => $request->getMethod() === 'POST')))->toBe(1);
})->with([true, false]);

test('unresolved or absent attempts cannot be resumed into a new provider POST', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    expect(fn () => $ctx->payments->resumeCheckout($ctx->invoice->getId()))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});

test('order changes during customer resolution cannot change accepted scope', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $response = $ctx->responses[Mollie\Api\Http\Requests\CreateCustomerRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\CreateCustomerRequest::class] = static function ($pending) use ($ctx, $response) {
        $ctx->order->setPeriod('1Y');
        $ctx->item->setPeriod('1Y');
        $ctx->em->flush();

        return $response($pending);
    };
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'changed during authorization')
        ->and($ctx->requests)->toBeEmpty()
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
});

test('legacy broad consent can settle but never becomes order authorization', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->db->update('molliepayments_attempt', ['consent_version' => 'invoice-collection-v1'], ['id' => mollieAttempt($ctx)['id']]);
    $payload = json_decode(mollieAttempt($ctx)['request'], true);
    $payload['metadata']['consent_version'] = 'invoice-collection-v1';
    unset($payload['metadata']['order_id'], $payload['metadata']['order_period']);
    $ctx->payload = $payload;
    $ctx->db->update('molliepayments_attempt', ['request' => json_encode($payload)], ['id' => mollieAttempt($ctx)['id']]);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->invoice->getStatus())->toBe('paid')->and(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review')
        ->and($ctx->mandateRequests)->toBeEmpty();
});

test('an order scheduled for cancellation cannot authorize renewal collection', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $meta = new Box\Mod\Order\Entity\OrderMeta();
    $meta->setOrder($ctx->order)->setName('cancel_at_period_end')->setValue('1');
    $ctx->em->persist($meta);
    $ctx->em->flush();
    expect(fn () => mollieFirst($ctx))->toThrow(Payment_Exception::class, 'eligible recurring order')
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});

test('ambiguous and custom invoice contents route only to oneoff checkout', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieIneligible($ctx, $case);
    expect($ctx->payments->checkoutRoute($ctx->invoice->getId(), $ctx->gateway->getId()))->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')->and($ctx->customerRequests)->toBeEmpty()
        ->and(mollieAttempt($ctx)['consent_version'])->toBeNull();
})->with(['custom', 'mixed']);

test('native invoice item lookup keeps onboarding scoped to its own invoice', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $other = clone $ctx->invoice;
    $other->setHash(bin2hex(random_bytes(20)));
    $ctx->em->persist($other);
    $ctx->em->flush();
    $foreign = clone $ctx->item;
    $foreign->setInvoice($other)->setType(InvoiceItem::TYPE_DEPOSIT);
    $ctx->em->persist($foreign);
    $ctx->em->flush();
    expect($ctx->payments->onboarding($ctx->invoice->getId())['order_id'])->toBe((string) $ctx->order->getId());
    mollieFirst($ctx);
    expect($ctx->payload['amount']['value'])->toBe('12.34')
        ->and($ctx->payload['metadata']['invoice_id'])->toBe((string) $ctx->invoice->getId());
});
