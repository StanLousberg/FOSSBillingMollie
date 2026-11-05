<?php

declare(strict_types=1);

use Box\Mod\Client\Entity\Client;
use Box\Mod\Molliepayments\Repository\CustomerRepository;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\GetCustomerRequest;

require_once dirname(__DIR__) . '/CustomerContext.php';

test('customer resolution is read only until explicitly requested and reuses without network calls', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    expect(mollieResolveCustomer($ctx, false))->toBeNull()->and($ctx->customerRequests)->toBe([]);
    expect(mollieResolveCustomer($ctx))->toBe('cst_disposable');
    $row = mollieCustomerRow($ctx);
    expect($row['status'])->toBe('ready')->and(count($ctx->customerRequests))->toBe(2);
    expect(mollieResolveCustomer($ctx))->toBe('cst_disposable')
        ->and(mollieResolveCustomer($ctx, false))->toBe('cst_disposable')
        ->and(count($ctx->customerRequests))->toBe(2);
    expect(mollieResolveCustomer($ctx, false, true))->toBe('cst_disposable')->and(count($ctx->customerRequests))->toBe(4);
    expect(array_keys(json_decode($row['request'], true)))->toBe(['metadata'])
        ->and($row['credential_fingerprint'])->not->toContain($ctx->key);
});

test('customer creation retries preserve exact payload and idempotency after lost response', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $response = $ctx->customerResponses[CreateCustomerRequest::class];
    $ctx->customerResponses[CreateCustomerRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new Mollie\Api\Exceptions\RequestTimeoutException($pending, null, 'Sensitive provider details');
    };
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'mapping is preserved');
    $before = mollieCustomerRow($ctx);
    expect($before['customer_id'])->toBeNull()->and($before['lease'])->toBeNull();
    expect(fn () => mollieResolveCustomer($ctx, false))->toThrow(Payment_Exception::class);
    $ctx->customerResponses[CreateCustomerRequest::class] = $response;
    expect(mollieResolveCustomer($ctx))->toBe('cst_disposable');
    $after = mollieCustomerRow($ctx);
    foreach (['id', 'request', 'idempotency_key', 'credential_fingerprint', 'created_at'] as $field) {
        expect($after[$field])->toBe($before[$field]);
    }
});

test('customer provider errors retain a recoverable identity and sanitize errors', function (int $status): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse(['detail' => 'secret test_key personal data'], $status);
    try {
        mollieResolveCustomer($ctx);
        test()->fail('Expected provider error');
    } catch (Payment_Exception $error) {
        expect($error->getMessage())->not->toContain('secret')->and($error->getPrevious())->toBeNull();
    }
    expect(mollieCustomerRow($ctx)['status'])->toBe('creating')->and(mollieCustomerRow($ctx)['customer_id'])->toBeNull();
})->with([401, 409, 422, 429, 500]);

test('customer unresolved creation cannot replay after the conservative idempotency window', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->db->update(CustomerRepository::TABLE, ['created_at' => time() - 2700], ['id' => $row['id']]);
    $before = count($ctx->customerRequests);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class)
        ->and(count($ctx->customerRequests))->toBe($before + 1);
    expect($ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_disposable'))->toBe('cst_disposable');
    expect(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
});

test('customer missing at provider blocks cached reuse and never creates a replacement', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->customerResponses[GetCustomerRequest::class] = new MockResponse([], 404);
    expect(fn () => mollieResolveCustomer($ctx, false, true))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['status'])->toBe('blocked');
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['customer_id'])->toBe('cst_disposable');
    expect(count(array_filter($ctx->customerRequests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
});

test('customer verification rejects mismatched identity metadata and mode', function (string $field): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->customerResponse = mollieCustomerResponse($ctx);
    $ctx->customerResponse[$field] = match ($field) {
        'id' => 'cst_other', 'mode' => 'live', 'metadata' => ['molliepayments_customer' => 'other'],
    };
    expect(fn () => mollieResolveCustomer($ctx, true, true))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['customer_id'])->toBe('cst_disposable');
})->with(['id', 'mode', 'metadata']);

test('customer creation rejects unexpected provider response', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponse = ['resource' => 'customer'];
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['customer_id'])->toBeNull();
});

test('customer rejects rotated credentials and changed profile without rebinding', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->profileId = 'pfl_other';
    expect(fn () => mollieResolveCustomer($ctx, false, true))->toThrow(Payment_Exception::class, 'profile context changed');
    $ctx->gateway->setConfig(json_encode(['test_api_key' => mollieDummyKey('test')]));
    $ctx->em->flush();
    $before = count($ctx->customerRequests);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'credential context changed')
        ->and(count($ctx->customerRequests))->toBe($before);
});

test('customer recovery rejects wrong client gateway mapping and replacement', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $row = mollieCustomerRow($ctx);
    expect(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), str_repeat('a', 32), 'cst_other'))->toThrow(Payment_Exception::class)
        ->and(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId() + 1, $row['id'], 'cst_other'))->toThrow(Payment_Exception::class)
        ->and(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_other'))->toThrow(Payment_Exception::class);
    $other = new Client();
    $other->setEmail('other@example.invalid');
    $ctx->em->persist($other);
    $ctx->em->flush();
    expect(fn () => $ctx->customers->recover($other->getId(), $ctx->gateway->getId(), $row['id'], 'cst_disposable'))->toThrow(Payment_Exception::class);
});

test('customer denies cross client and unauthenticated access before network or persistence', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    expect(fn () => $ctx->customers->resolve($ctx->client->getId() + 1, $ctx->gateway->getId(), true))->toThrow(Payment_Exception::class, 'access denied');
    $ctx->di['loggedin_client'] = null;
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'access denied')
        ->and($ctx->customerRequests)->toBe([])->and(mollieCustomerRow($ctx))->toBeFalse();
});

test('customer test and live mappings stay isolated and select only their saved key', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->gateway->setTestMode(false)->setConfig(json_encode(['api_key' => mollieDummyKey('live'), 'test_api_key' => $ctx->key]));
    $ctx->em->flush();
    expect(mollieResolveCustomer($ctx, false))->toBeNull();
    // Provider ID must differ between modes, as in the real API.
    $response = $ctx->customerResponses[CreateCustomerRequest::class];
    $ctx->customerResponses[CreateCustomerRequest::class] = static function ($pending) use ($ctx, $response) {
        $ctx->customerResponse = mollieCustomerResponse($ctx);
        $ctx->customerResponse['id'] = 'cst_live';
        expect($pending->createPsrRequest()->getHeaderLine('Authorization'))->not->toContain($ctx->key);

        return $response($pending);
    };
    expect(mollieResolveCustomer($ctx))->toBe('cst_live')
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(2);
});

test('customer reservation and lease serialize competing requests and fence stale workers', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $repo = new CustomerRepository($ctx->db);
    $competing = $row;
    $competing['id'] = str_repeat('f', 32);
    $competing['idempotency_key'] = $competing['id'];
    expect($repo->reserve($competing)['id'])->toBe($row['id'])
        ->and($repo->acquire($row['id'], 'owner'))->toBeTrue();
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'already in progress');
    expect(fn () => $repo->update($row['id'], 'stale', ['customer_id' => 'cst_wrong']))->toThrow(Payment_Exception::class, 'lost its lease');
    $repo->release($row['id'], 'stale');
    expect(mollieCustomerRow($ctx)['lease'])->toBe('owner');
});

test('customer operations reject outer transactions and invalid gateways', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->db->beginTransaction();
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'committed');
    $ctx->db->rollBack();
    $ctx->gateway->setGateway('Other');
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'gateway');
});

test('customer schema update preserves existing payment history and extra columns', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $before = mollieAttempt($ctx);
    $ctx->db->executeStatement('DROP TABLE molliepayments_customer');
    $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt ADD legacy_marker VARCHAR(32) DEFAULT NULL');
    $ctx->services['molliepayments']->update();
    mollieResolveCustomer($ctx);
    $customer = mollieCustomerRow($ctx);
    $ctx->services['molliepayments']->update();
    expect(mollieCustomerRow($ctx))->toBe($customer)
        ->and(mollieAttempt($ctx))->toBe($before + ['legacy_marker' => null]);
});

test('one time checkout does not create or request a customer', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect($ctx->customerRequests)->toBe([])->and(mollieCustomerRow($ctx))->toBeFalse()
        ->and($ctx->invoice->getStatus())->toBe('paid');
});

test('customer profile outage does not create an unscoped reservation', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[Mollie\Api\Http\Requests\GetCurrentProfileRequest::class] = new MockResponse([], 503);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'unavailable')
        ->and(mollieCustomerRow($ctx))->toBeFalse();
});

test('customer recovery rejects foreign provider metadata and retains unresolved identity', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->customerResponse = ['resource' => 'customer', 'id' => 'cst_foreign', 'mode' => 'test', 'metadata' => ['molliepayments_customer' => 'foreign']];
    expect(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_foreign'))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['customer_id'])->toBeNull()
        ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
});

test('customer expired worker lease can retry without changing creation identity', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $response = $ctx->customerResponses[CreateCustomerRequest::class];
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->db->update(CustomerRepository::TABLE, ['lease' => 'crashed-worker', 'lease_until' => time() - 1], ['id' => $row['id']]);
    $ctx->customerResponses[CreateCustomerRequest::class] = $response;
    expect(mollieResolveCustomer($ctx))->toBe('cst_disposable')
        ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
});

test('customer provider mapping uniqueness prevents assigning one provider ID to two clients', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $row = mollieCustomerRow($ctx);
    $row['id'] = bin2hex(random_bytes(16));
    $row['idempotency_key'] = $row['id'];
    $row['client_id'] = $ctx->client->getId() + 1;
    expect(fn () => $ctx->db->insert(CustomerRepository::TABLE, $row))->toThrow(Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
});

test('customer read only resolution preserves unresolved state without provider calls', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $before = mollieCustomerRow($ctx);
    $requests = count($ctx->customerRequests);
    expect(fn () => mollieResolveCustomer($ctx, false))->toThrow(Payment_Exception::class, 'unresolved')
        ->and(mollieCustomerRow($ctx))->toBe($before)
        ->and(count($ctx->customerRequests))->toBe($requests);
});

test('customer expired creation retains its specific recovery diagnostic', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $ctx->db->update(CustomerRepository::TABLE, ['created_at' => time() - 2700], ['id' => $row['id']]);
    foreach ([1, 2] as $retry) {
        expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'requires operator recovery')
            ->and(mollieCustomerRow($ctx)['error'])->toBe('recovery_required')
            ->and(mollieCustomerRow($ctx)['lease'])->toBeNull()
            ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
    }
});

test('customer observed profile mismatch blocks subsequent cached reuse until verified', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $profile = $ctx->profileId;
    $ctx->profileId = 'pfl_othermerchant';
    expect(fn () => mollieResolveCustomer($ctx, false, true))->toThrow(Payment_Exception::class, 'profile context changed')
        ->and(mollieCustomerRow($ctx)['status'])->toBe('blocked')
        ->and(mollieCustomerRow($ctx)['error'])->toBe('profile_context_changed');
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'profile context changed');
    $ctx->profileId = $profile;
    expect(mollieResolveCustomer($ctx, false, true))->toBe('cst_disposable')
        ->and(mollieCustomerRow($ctx)['status'])->toBe('ready');
});

test('customer rejected replacement preserves a healthy mapping and makes no provider calls', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $row = mollieCustomerRow($ctx);
    $requests = count($ctx->customerRequests);
    expect(fn () => $ctx->customers->recover($ctx->client->getId(), $ctx->gateway->getId(), $row['id'], 'cst_replacement'))->toThrow(Payment_Exception::class, 'cannot replace')
        ->and(mollieCustomerRow($ctx))->toBe($row)
        ->and(count($ctx->customerRequests))->toBe($requests)
        ->and(mollieResolveCustomer($ctx))->toBe('cst_disposable');
});

test('multiple clients create and independently verify their own provider Customers', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $providerCustomer = static function () use ($ctx): array {
        $customer = mollieCustomerResponse($ctx);
        $customer['id'] = 'cst_client' . $ctx->client->getId();

        return $customer;
    };
    $ctx->customerResponses[CreateCustomerRequest::class] = static fn () => MockResponse::created($providerCustomer());
    $ctx->customerResponses[GetCustomerRequest::class] = static fn () => MockResponse::ok($providerCustomer());
    $clients = [$ctx->client];
    for ($i = 0; $i < 2; ++$i) {
        $client = new Client();
        $ctx->em->persist($client);
        $clients[] = $client;
    }
    $ctx->em->flush();
    $ids = [];
    foreach ($clients as $client) {
        $ctx->client = $client;
        $ctx->di['loggedin_client'] = $client;
        $ids[] = mollieResolveCustomer($ctx);
        expect(mollieResolveCustomer($ctx, false, true))->toBe(end($ids));
    }
    expect(count(array_unique($ids)))->toBe(3)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(3);
    foreach ($clients as $index => $client) {
        $ctx->client = $client;
        $ctx->di['loggedin_client'] = $client;
        expect(mollieResolveCustomer($ctx))->toBe($ids[$index]);
        $other = $clients[($index + 1) % 3];
        expect(fn () => $ctx->customers->resolve($other->getId(), $ctx->gateway->getId(), true))->toThrow(Payment_Exception::class, 'access denied');
    }
});

test('customer unresolved creation cannot replay with a rotated credential', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $before = mollieCustomerRow($ctx);
    $requests = count($ctx->customerRequests);
    $ctx->gateway->setConfig(json_encode(['test_api_key' => mollieDummyKey('test')]));
    $ctx->em->flush();
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'credential context changed')
        ->and(mollieCustomerRow($ctx))->toBe($before)
        ->and(count($ctx->customerRequests))->toBe($requests);
});

test('customer verification rejects a wrong resource type', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    mollieResolveCustomer($ctx);
    $ctx->customerResponse = mollieCustomerResponse($ctx);
    $ctx->customerResponse['resource'] = 'payment';
    expect(fn () => mollieResolveCustomer($ctx, false, true))->toThrow(Payment_Exception::class)
        ->and(mollieCustomerRow($ctx)['status'])->toBe('blocked');
});
