<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Repository\CustomerRepository;
use Box\Mod\Molliepayments\Repository\MandateRepository;
use Box\Mod\Molliepayments\Support\Timestamps;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetCurrentProfileRequest;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;

require_once dirname(__DIR__) . '/AdminMandateContext.php';

test('admin discovery verifies provider pages and exposes only safe metadata without granting consent', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $history = mollieMandateHistory($ctx);
    $ctx->client->setFirstName('Example')->setLastName('Customer');
    $ctx->em->flush();
    $before = $ctx->adminMandates->overview([], 1);
    expect($before['list'][0]['mandate_id'])->toBeNull();
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue();
    $detail = $ctx->adminMandates->detail($ctx->mappingId, 'mdt_disposable');
    expect($detail['status'])->toBe('valid')->and($detail['references'])->toBeEmpty()
        ->and($detail['signature_date'])->toBe('2026-10-01')->and($detail['summary'])->toBe('Bank account **** 0000')
        ->and($detail['customer_name'])->toBe('Example Customer')->and($detail['audit'])->not->toBeEmpty()
        ->and(json_encode($detail))->not->toContain('NL00TEST', 'Private Instrument', $ctx->key)
        ->and(mollieMandateHistory($ctx))->toBe($history);
    foreach ([['customer' => 'Example Customer'], ['customer' => 'Example'], ['customer' => (string) $ctx->client->getId()], ['mandate' => 'disposable'], ['status' => 'valid'], ['environment' => 'test']] as $filter) {
        expect($ctx->adminMandates->overview($filter, 1)['total'])->toBe(1);
    }
    expect($ctx->adminMandates->overview(['environment' => 'live'], 1)['total'])->toBe(0);
    foreach ($ctx->adminRequests as $request) {
        expect($request->getMethod())->toBe('GET')->and($request->getUri()->getQuery())->not->toContain('testmode');
    }
});

test('provider discovery follows validated pagination and native overview paginates', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $second = $ctx->adminMandate;
    $second['id'] = 'mdt_second';
    $ctx->adminPages[] = [$second];
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue()
        ->and($ctx->adminMandates->overview(['view' => 'mandates'], 1)['total'])->toBe(2);
    for ($i = 0; $i < 25; ++$i) {
        (new MandateRepository($ctx->db))->save($ctx->mappingId, 'mdt_extra' . $i, []);
    }
    expect($ctx->adminMandates->overview(['view' => 'mandates'], 1)['list'])->toHaveCount(25)
        ->and($ctx->adminMandates->overview(['view' => 'mandates'], 2)['list'])->toHaveCount(2);
});

test('admin refresh distinguishes provider states and confirmed missing mandates', function (string $state): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    if ($state === 'missing') {
        $ctx->adminResponses[GetMandateRequest::class] = MockResponse::error(404, 'Missing', 'Private provider message');
    } else {
        $ctx->adminMandate['status'] = $state;
    }
    expect($ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['status'])->toBe($state)
        ->and(mollieAdminMandateRow($ctx)['error'])->toBeNull();
})->with(['valid', 'pending', 'invalid', 'missing']);

test('omission from list triggers direct verification and never implies revocation', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminPages = [[]];
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['status'])->toBe('valid');
    $ctx->adminResponses[GetMandateRequest::class] = MockResponse::error(410, 'Removed', 'Private');
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['status'])->toBe('missing');
});

test('provider verification outages and unknown states preserve historical status but mark stale', function (string $failure): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $before = mollieAdminMandateRow($ctx);
    if ($failure === 'unknown') {
        $ctx->adminMandate['status'] = 'unknown';
    } elseif ($failure === 'empty') {
        $ctx->adminResponses[GetMandateRequest::class] = MockResponse::noContent();
    } else {
        $ctx->adminResponses[GetMandateRequest::class] = MockResponse::error(503, 'Unavailable', 'Private ' . $ctx->key);
    }
    $result = $ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7);
    expect($result['success'])->toBeFalse()->and(mollieAdminMandateRow($ctx)['status'])->toBe('valid')
        ->and(mollieAdminMandateRow($ctx)['verified_at'])->toBe($before['verified_at'])
        ->and(mollieAdminMandateRow($ctx)['error'])->toBe('verification_failed')
        ->and(json_encode([$result, $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate_audit')]))->not->toContain('Private', $ctx->key);
})->with(['unknown', 'empty', 'outage']);

test('admin revocation is confirmed and duplicates never repeat DELETE or mutate payment history', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    mollieAdminMandates($ctx, false);
    $history = mollieMandateHistory($ctx);
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    expect($ctx->adminMandates->detail($ctx->mappingId, 'mdt_disposable')['references'])->toHaveCount(1);
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['revocation'])->toBe('confirmed');
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and($ctx->deleteCount)->toBe(1)->and(mollieMandateHistory($ctx))->toBe($history);
});

test('unconfirmed rejected and lost revocations never report success or repeat DELETE', function (int $status): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminResponses[RevokeMandateRequest::class] = static function () use ($ctx, $status) {
        ++$ctx->deleteCount;

        return $status === 204 ? MockResponse::noContent() : MockResponse::error($status, 'Rejected', 'Secret ' . $ctx->key);
    };
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and($ctx->deleteCount)->toBe(1)->and(mollieAdminMandateRow($ctx)['revocation'])->toBe(in_array($status, [403, 422], true) ? 'rejected' : 'requested');
    $ctx->adminMandate['status'] = 'invalid';
    expect($ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['revocation'])->toBe(in_array($status, [403, 422], true) ? 'rejected' : 'confirmed');
})->with([204, 403, 422, 429, 503]);

test('lost DELETE response with provider invalidity reconciles successfully', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminResponses[RevokeMandateRequest::class] = static function () use ($ctx) {
        ++$ctx->deleteCount;
        $ctx->adminMandate['status'] = 'invalid';

        return MockResponse::error(503, 'Unavailable', 'Private');
    };
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['revocation'])->toBe('confirmed');
});

test('admin mandate operations reject foreign provider context and never send DELETE', function (string $field): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    if ($field === 'credential') {
        $ctx->gateway->setConfig(mollieGatewayValue('config', ['test_api_key' => mollieDummyKey('test')]));
        $ctx->em->flush();
    } elseif ($field === 'environment') {
        $ctx->gateway->setTestMode(false);
        $ctx->em->flush();
    } elseif ($field === 'profile') {
        $ctx->adminResponses[GetCurrentProfileRequest::class] = MockResponse::ok(['resource' => 'profile', 'id' => 'pfl_foreign']);
    } elseif ($field === 'customer') {
        $ctx->adminMandate['customerId'] = 'cst_foreign';
    } elseif ($field === 'mode') {
        $ctx->adminMandate['mode'] = 'live';
    } else {
        $ctx->adminMandate['id'] = 'mdt_foreign';
    }
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and($ctx->deleteCount)->toBe(0);
})->with(['credential', 'environment', 'profile', 'customer', 'mode', 'mandate']);

test('concurrent mapping operation blocks DELETE and stale workers cannot overwrite newer snapshots', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $customers = new CustomerRepository($ctx->db);
    $lease = bin2hex(random_bytes(16));
    expect($customers->acquire($ctx->mappingId, $lease))->toBeTrue();
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()->and($ctx->deleteCount)->toBe(0);
    $customers->release($ctx->mappingId, $lease);
    $ctx->adminResponses[GetMandateRequest::class] = static function () use ($ctx) {
        $ctx->db->update(CustomerRepository::TABLE, ['lease' => 'new-worker', 'lease_until' => Timestamps::now(180)], ['id' => $ctx->mappingId]);
        $ctx->adminMandate['status'] = 'invalid';

        return MockResponse::ok($ctx->adminMandate);
    };
    expect($ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and(mollieAdminMandateRow($ctx)['status'])->toBe('valid');
});

test('discovery never creates reassigns or recovers an unverified Customer mapping', function (string $state): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->db->update(CustomerRepository::TABLE, ['status' => $state], ['id' => $ctx->mappingId]);
    $before = mollieCustomerRow($ctx);
    $count = count($ctx->customerRequests);
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeFalse()
        ->and(mollieCustomerRow($ctx))->toBe($before)->and(count($ctx->customerRequests))->toBe($count)->and($ctx->adminRequests)->toBeEmpty();
})->with(['creating', 'blocked']);

test('synchronization checks onboarding mandates that disappeared before the first admin discovery', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    mollieAdminMandates($ctx, false);
    $history = mollieMandateHistory($ctx);
    $ctx->adminPages = [[]];
    $ctx->adminResponses[GetMandateRequest::class] = MockResponse::error(404, 'Missing', 'Private');
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['status'])->toBe('missing')->and(mollieMandateHistory($ctx))->toBe($history);
});

test('revocation reconciliation outages preserve the durable marker and cannot resend DELETE', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminResponses[GetMandateRequest::class] = static function () use ($ctx) {
        return $ctx->deleteCount > 0 ? MockResponse::error(503, 'Outage', 'Private') : MockResponse::ok($ctx->adminMandate);
    };
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and(mollieAdminMandateRow($ctx)['revocation'])->toBe('requested')->and(mollieAdminMandateRow($ctx)['error'])->toBe('verification_failed')
        ->and($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()->and($ctx->deleteCount)->toBe(1);
    $ctx->adminResponses[GetMandateRequest::class] = MockResponse::error(404, 'Missing', 'Private');
    expect($ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['revocation'])->toBe('confirmed');
});

test('invalid provider pagination is rejected without following foreign URLs', function (string $next): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminResponses[GetPaginatedMandateRequest::class] = static function ($pending) use ($ctx, $next) {
        $ctx->adminRequests[] = $pending->createPsrRequest();

        return MockResponse::ok(['count' => 1, '_embedded' => ['mandates' => [$ctx->adminMandate]], '_links' => ['next' => ['href' => $next]]]);
    };
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeFalse()->and($ctx->adminRequests)->toHaveCount(1);
})->with(['https://untrusted.invalid/?from=mdt_other', 'https://api.mollie.com/v2/customers/cst_foreign/mandates?from=mdt_other', 'https://api.mollie.com/v2/customers/cst_disposable/mandates?from[]=mdt_other']);

test('provider signature dates and instrument metadata cannot introduce raw private fields', function (string $method, array $details, string $summary): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandate['method'] = $method;
    $ctx->adminMandate['details'] = $details;
    $ctx->adminPages = [[$ctx->adminMandate]];
    expect($ctx->adminMandates->synchronize($ctx->mappingId, 7)['success'])->toBeTrue()
        ->and(mollieAdminMandateRow($ctx)['summary'])->toBe($summary);
    $ctx->adminMandate['signatureDate'] = '2026-02-31';
    expect($ctx->adminMandates->refresh($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and(mollieAdminMandateRow($ctx)['signature_date'])->toBe('2026-10-01');
})->with([
    ['creditcard', ['cardNumber' => '1234567890123456', 'cardHolder' => 'Private'], 'Card **** 3456'],
    ['creditcard', ['cardNumber' => '1234'], 'Card **** 1234'],
    ['paypal', ['consumerAccount' => 'private@example.invalid'], 'PayPal account (details hidden)'],
]);

test('SDK transport failures and credential rotation during an operation never authorize revocation', function (bool $rotate): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminResponses[GetMandateRequest::class] = static function ($pending) use ($ctx, $rotate) {
        if (!$rotate) {
            throw new Mollie\Api\Exceptions\NetworkRequestException($pending, null, 'Private ' . $ctx->key);
        }
        $ctx->gateway->setConfig(mollieGatewayValue('config', ['test_api_key' => mollieDummyKey('test')]));
        $ctx->em->flush();

        return MockResponse::ok($ctx->adminMandate);
    };
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and($ctx->deleteCount)->toBe(0);
})->with([false, true]);

test('details retain explicit payment references after invalidation and withhold corrupted consent evidence', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    mollieAdminMandates($ctx, false);
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->db->update('molliepayments_attempt', ['mandate_verified_at' => null, 'onboarding_status' => 'mandate_invalid'], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->adminMandates->detail($ctx->mappingId, 'mdt_disposable')['references'])->toHaveCount(1);
    $ctx->db->update('molliepayments_attempt', ['consent_text' => 'Corrupted evidence'], ['id' => mollieAttempt($ctx)['id']]);
    $detail = $ctx->adminMandates->detail($ctx->mappingId, 'mdt_disposable');
    expect($detail['references'])->toBeEmpty()->and($detail['unverified_references'])->toBeTrue();
});

test('SDK retryable transport failures cannot replay a destructive mandate request', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $ctx->adminResponses[RevokeMandateRequest::class] = static function ($pending) use ($ctx): never {
        ++$ctx->deleteCount;
        throw new Mollie\Api\Exceptions\RetryableNetworkRequestException($pending, 'Private transport failure');
    };
    expect($ctx->adminMandates->revoke($ctx->mappingId, 'mdt_disposable', 7)['success'])->toBeFalse()
        ->and($ctx->deleteCount)->toBe(1)->and(mollieAdminMandateRow($ctx)['revocation'])->toBe('requested');
});

test('customer pages preserve groups and default to the enabled gateway environment', function (): void {
    mollieCheckMandatePages(mollieAdminMandates(mollieOnboarding(molliePaymentContext())));
});

test('upgrades preserve rejected revoke evidence after later provider invalidity', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $repo = new MandateRepository($ctx->db);
    $repo->save($ctx->mappingId, 'mdt_legacy', ['status' => 'invalid', 'revocation' => 'confirmed']);
    $repo->audit($ctx->mappingId, 'mdt_legacy', 7, 'revoke', 'provider_rejected');
    $before = $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate_audit');
    $ctx->services['molliepayments']->update();
    expect($repo->find($ctx->mappingId, 'mdt_legacy')['revocation'])->toBe('rejected')
        ->and($ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate_audit'))->toBe($before);
});
