<?php

declare(strict_types=1);

use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetCurrentProfileRequest;
use Mollie\Api\Http\Requests\GetCustomerRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;

require_once dirname(__DIR__) . '/MandateContext.php';

test('instrument matches without explicit payment references never authorize a mandate', function (string $method): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()), $method);
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    expect($row['onboarding_status'])->toBe('pending_mandate')->and($row['mandate_id'])->toBeNull()
        ->and($ctx->invoice->getStatus())->toBe('paid')->and($row['request'])->not->toContain('NL00TEST', 'disposable-test-fingerprint');
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect(mollieAttempt($ctx)['mandate_id'])->toBeNull()
        ->and($ctx->mandateRequests)->toBeEmpty()
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
})->with(['ideal', 'creditcard', 'paypal']);

test('unrelated mandate listings cannot prove a payment association', function (string $case, string $status): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    if ($case === 'ambiguous') {
        $ctx->discoveryMandates[] = array_replace($ctx->boundMandate, ['id' => 'mdt_second']);
    } elseif ($case === 'paginated') {
        $ctx->discoveryNext = true;
    } elseif ($case === 'empty') {
        $ctx->discoveryMandates = [];
    } elseif ($case === 'old') {
        $ctx->discoveryMandates[0]['createdAt'] = gmdate(DATE_ATOM, time() - 3600);
    } elseif ($case === 'foreign') {
        $ctx->discoveryMandates[0]['customerId'] = 'cst_other';
    } elseif ($case === 'mode') {
        $ctx->discoveryMandates[0]['mode'] = 'live';
    } elseif ($case === 'instrument') {
        $ctx->discoveryMandates[0]['details']['consumerAccount'] = 'NL00OTHER00000000';
    } elseif ($case === 'no_details') {
        $mutate = $ctx->mutatePayment;
        $ctx->mutatePayment = static function (array $payment) use ($mutate): array {
            $payment = $mutate($payment);
            unset($payment['details']);

            return $payment;
        };
    }
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe($status)
        ->and(mollieAttempt($ctx)['mandate_id'])->toBeNull()->and($ctx->invoice->getStatus())->toBe('paid');
})->with([['ambiguous', 'pending_mandate'], ['paginated', 'pending_mandate'], ['empty', 'pending_mandate'], ['old', 'pending_mandate'], ['foreign', 'pending_mandate'], ['mode', 'pending_mandate'], ['instrument', 'pending_mandate'], ['no_details', 'pending_mandate']]);

test('an explicitly linked pending mandate becomes valid without creating another first payment', function (): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    $ctx->paymentMandateId = 'mdt_bound';
    $ctx->boundMandate['status'] = 'pending';
    $ctx->discoveryMandates = [$ctx->boundMandate];
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate')->and(mollieAttempt($ctx)['mandate_id'])->toBe('mdt_bound');
    $ctx->boundMandate['status'] = 'valid';
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
});

test('temporary provider failures preserve ready evidence and do not block the Customer mapping', function (string $endpoint, int $status): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $class = match ($endpoint) {
        'mandate' => GetMandateRequest::class, 'scope' => GetPaginatedMandateRequest::class, 'customer' => GetCustomerRequest::class, 'profile' => GetCurrentProfileRequest::class,
    };
    $ctx->responses[$class] = new MockResponse(['title' => 'Unavailable', 'status' => $status, 'detail' => 'Do not log this provider detail'], $status);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBe($row['mandate_verified_at'])
        ->and(mollieAttempt($ctx)['mandate_id'])->toBe($row['mandate_id'])
        ->and(mollieCustomerRow($ctx)['status'])->toBe('ready');
    $logged = json_encode($ctx->di['logger']->calls);
    expect($logged)->not->toContain('Do not log this provider detail', $ctx->key)
        ->and($logged)->toContain('provider_unavailable', $row['id']);
})->with(['mandate', 'scope', 'customer', 'profile'])->with([429, 503]);

test('explicit invalidation or deletion removes readiness and never selects a replacement mandate', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $before = count($ctx->mandateRequests);
    if ($case === 'invalid') {
        $ctx->mandateStatus = 'invalid';
    } else {
        $ctx->responses[GetMandateRequest::class] = new MockResponse(['status' => 404, 'title' => 'Not found'], 404);
    }
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('mandate_invalid')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull()->and(mollieAttempt($ctx)['mandate_status'])->toBe('invalid')
        ->and($ctx->invoice->getStatus())->toBe('paid');
    // No discovery scan was introduced after explicit invalidation.
    expect(count($ctx->mandateRequests) - $before)->toBe($case === 'invalid' ? 1 : 0);
})->with(['invalid', 'deleted']);

test('native cron rechecks only cooled-down pending mandates and never creates a payment or Customer', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mandateStatus = 'pending';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    expect($ctx->payments->retryPendingMandates())->toBe(0);
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-360)], ['id' => $row['id']]);
    $ctx->mandateStatus = 'valid';
    $dispatcher = new FOSSBilling\Core\Events\EventDispatcher(static fn () => ['Molliepayments'], static fn () => $ctx->services['molliepayments']);
    $dispatcher->dispatch(new Box\Mod\Cron\Event\AfterAdminCronRunEvent());
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and($ctx->payments->retryPendingMandates())->toBe(0)
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(1);
});

test('a pending discovery without evidence eventually requires operator review', function (): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    $ctx->discoveryMandates = [];
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $ctx->db->update('molliepayments_attempt', ['created_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-604800)], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('manual_review')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1);
});

test('an existing mandate lease prevents duplicate verification and stale writes', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $requests = count($ctx->mandateRequests);
    $ctx->db->update('molliepayments_attempt', ['lease' => str_repeat('a', 32), 'lease_until' => Box\Mod\Molliepayments\Support\Timestamps::now(180)], ['id' => $row['id']]);
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect(count($ctx->mandateRequests))->toBe($requests)
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBe($row['mandate_verified_at'])
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready');
});

test('a mandate response after losing the lease cannot overwrite a newer observation', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $ctx->responses[GetMandateRequest::class] = static function () use ($ctx, $row) {
        $ctx->db->update('molliepayments_attempt', ['lease' => str_repeat('b', 32), 'onboarding_status' => 'mandate_invalid', 'mandate_status' => 'invalid', 'mandate_verified_at' => null], ['id' => $row['id']]);

        return MockResponse::ok(mollieMandate($ctx));
    };
    $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('mandate_invalid')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull();
});

test('cron payment-read outages back off without losing pending mandate state', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mandateStatus = 'pending';
    mollieWebhook($ctx);
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = new MockResponse(['status' => 503, 'title' => 'Unavailable'], 503);
    expect($ctx->payments->retryPendingMandates())->toBe(1)->and($ctx->payments->retryPendingMandates())->toBe(0)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate')
        ->and($ctx->invoice->getStatus())->toBe('paid');
});

test('native cron processes at most five valid pending attempts per batch', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->mandateStatus = 'pending';
    for ($i = 0; $i < 6; ++$i) {
        if ($i > 0) {
            $invoice = new Box\Mod\Invoice\Entity\Invoice();
            $invoice->setClientId($ctx->client->getId())->setCurrency('EUR')->setIssued(true)->setHash(bin2hex(random_bytes(20)))->setTaxrate(0);
            $ctx->em->persist($invoice);
            $ctx->em->flush();
            $item = new Box\Mod\Invoice\Entity\InvoiceItem();
            $item->setInvoice($invoice)->setTitle('Disposable recurring batch')->setType('order')->setRelId((string) $ctx->order->getId())->setPeriod('1M')->setQuantity(1)->setPrice('12.34');
            $ctx->em->persist($item);
            $ctx->em->flush();
            $ctx->invoice = $invoice;
            $ctx->item = $item;
        }
        $ctx->paymentId = 'tr_batch' . $i;
        $ctx->status = 'open';
        mollieFirst($ctx);
        $ctx->status = 'paid';
        mollieWebhook($ctx);
    }
    $ctx->db->executeStatement('UPDATE molliepayments_attempt SET updated_at = ?', [Box\Mod\Molliepayments\Support\Timestamps::now(-360)]);
    $ctx->mandateStatus = 'valid';
    $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = static function ($pending) use ($ctx) {
        $ctx->requests[] = $pending->createPsrRequest();
        $ctx->paymentId = basename($pending->createPsrRequest()->getUri()->getPath());
        $row = $ctx->db->fetchAssociative('SELECT * FROM molliepayments_attempt WHERE payment_id = ?', [$ctx->paymentId]);
        $ctx->payload = json_decode($row['request'], true);
        $ctx->invoice = $ctx->em->find(Box\Mod\Invoice\Entity\Invoice::class, $row['invoice_id']);

        return MockResponse::ok(mollieProviderPayment($ctx));
    };
    expect($ctx->payments->retryPendingMandates())->toBe(5)
        ->and($ctx->db->fetchOne("SELECT COUNT(*) FROM molliepayments_attempt WHERE onboarding_status = 'ready'"))->toBe(5)
        ->and($ctx->payments->retryPendingMandates())->toBe(1)
        ->and($ctx->payments->retryPendingMandates())->toBe(0)
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(6);
});

test('temporary Customer lease contention preserves a previously verified mandate', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $requests = count($ctx->mandateRequests);
    $ctx->db->update('molliepayments_customer', ['lease' => str_repeat('c', 32), 'lease_until' => Box\Mod\Molliepayments\Support\Timestamps::now(180)], ['id' => mollieCustomerRow($ctx)['id']]);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBe($row['mandate_verified_at'])
        ->and(count($ctx->mandateRequests))->toBe($requests);
});

test('card last-four equality is never sufficient for mandate discovery', function (): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()), 'creditcard');
    $ctx->mutatePayment = static function (array $payment): array {
        $payment['method'] = 'creditcard';
        $payment['createdAt'] = gmdate(DATE_ATOM, time() - 120);
        $payment['details'] = ['cardNumber' => '1234'];

        return $payment;
    };
    $ctx->boundMandate['details'] = ['cardNumber' => '1234'];
    $ctx->discoveryMandates = [$ctx->boundMandate];
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate')
        ->and($ctx->mandateRequests)->toBeEmpty();
});

test('a late explicit payment mandate reference recovers without another first payment', function (bool $expired): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    $ctx->discoveryMandates = [];
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
    if ($expired) {
        $ctx->db->update('molliepayments_attempt', ['created_at' => Box\Mod\Molliepayments\Support\Timestamps::now(-604801)], ['id' => mollieAttempt($ctx)['id']]);
        expect($ctx->payments->retryPendingMandates())->toBe(1)
            ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review');
    }
    $transactions = $ctx->db->fetchOne('SELECT COUNT(*) FROM ' . $ctx->db->quoteSingleIdentifier('transaction'));
    $ctx->paymentMandateId = 'mdt_bound';
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(1)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM ' . $ctx->db->quoteSingleIdentifier('transaction')))->toBe($transactions);
})->with([false, true]);

test('SDK-wrapped transport failures retain ready evidence without leaking the transport error', function (): void {
    // The SDK retries network errors; retain fake responses for every retry.
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    $ctx->paymentMandateId = 'mdt_bound';
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $row = mollieAttempt($ctx);
    $ctx->responses[GetMandateRequest::class] = static function ($pending): never {
        throw new class($pending->createPsrRequest()) extends RuntimeException implements Psr\Http\Client\NetworkExceptionInterface {
            public function __construct(private readonly Psr\Http\Message\RequestInterface $request)
            {
                parent::__construct('Private transport error must never be logged');
            }

            public function getRequest(): Psr\Http\Message\RequestInterface
            {
                return $this->request;
            }
        };
    };
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('ready')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBe($row['mandate_verified_at'])
        ->and(json_encode($ctx->di['logger']->calls))->not->toContain('Private transport error', $ctx->key);
});

test('overlapping first payments for the same instrument never share an unproven mandate', function (string $method): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()), $method);
    $ctx->paymentId = 'tr_overlapA';
    mollieFirst($ctx);
    $firstInvoice = $ctx->invoice;
    $firstItem = $ctx->item;
    $firstPayload = $ctx->payload;
    $firstAttempt = mollieAttempt($ctx);
    // Both checkouts are open for the same Customer and instrument. Only B has a provider relation.
    mollieAdditionalRecurringInvoice($ctx);
    $ctx->paymentId = 'tr_overlapB';
    $ctx->paymentMandateId = 'mdt_bound';
    mollieFirst($ctx);
    expect($ctx->payload['customerId'])->toBe($firstPayload['customerId']);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe('ready');
    $ctx->invoice = $firstInvoice;
    $ctx->item = $firstItem;
    $ctx->payload = $firstPayload;
    $ctx->paymentId = 'tr_overlapA';
    $ctx->paymentMandateId = null;
    $requests = count($ctx->mandateRequests);
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['id'])->toBe($firstAttempt['id'])
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate')
        ->and(mollieAttempt($ctx)['mandate_id'])->toBeNull()
        ->and(count($ctx->mandateRequests))->toBe($requests);
})->with(['ideal', 'creditcard']);

test('mandate association requires consistent explicit provider relationships', function (string $case, string $status): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    $mutate = $ctx->mutatePayment;
    $ctx->mutatePayment = static function (array $payment) use ($mutate, $case): array {
        $payment = $mutate($payment);
        if ($case === 'link') {
            $payment['_links']['mandate'] = ['href' => 'https://api.mollie.com/v2/customers/cst_disposable/mandates/mdt_bound'];
        } elseif ($case === 'contradictory') {
            $payment['mandateId'] = 'mdt_other';
            $payment['_links']['mandate'] = ['href' => 'https://api.mollie.com/v2/customers/cst_disposable/mandates/mdt_bound'];
        } elseif ($case === 'foreign_link') {
            $payment['_links']['mandate'] = ['href' => 'https://api.mollie.com/v2/customers/cst_other/mandates/mdt_bound'];
        }

        return $payment;
    };
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieAttempt($ctx)['onboarding_status'])->toBe($status);
    if ($case === 'link') {
        expect(mollieAttempt($ctx)['mandate_id'])->toBe('mdt_bound');
    }
})->with([['link', 'ready'], ['contradictory', 'manual_review'], ['foreign_link', 'manual_review']]);

test('a previously heuristically stored ID cannot substitute for a missing payment relation', function (): void {
    $ctx = mollieDiscoverableMandate(mollieOnboarding(molliePaymentContext()));
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    $ctx->db->update('molliepayments_attempt', ['mandate_id' => 'mdt_bound', 'onboarding_status' => 'ready', 'mandate_status' => 'valid'], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->reverifyOnboarding($ctx->invoice->getId())['status'])->toBe('pending_mandate')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull();
});
