<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\CustomerRequest;
use FOSSBilling\Core\Security\Crypt;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;

require_once dirname(__DIR__) . '/CustomerContext.php';

test('new customer sends normalized native full name and email with only opaque metadata', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->client->setFirstName("  Zoë\t李 ")->setLastName("  García\u{00A0} López  ")->setEmail('  zoe@example.invalid  ');
    $ctx->client->setLang('nl_NL');
    $ctx->em->flush();
    mollieResolveCustomer($ctx);
    expect($ctx->customerPayload)->toEqual(['name' => 'Zoë 李 García López', 'email' => 'zoe@example.invalid',
        'metadata' => ['molliepayments_customer' => mollieCustomerRow($ctx)['id']]])
        ->not->toHaveKeys(['locale', 'address', 'phone', 'testmode'])
        ->and(mollieCustomerRow($ctx)['request'])->not->toContain('Zoë', 'zoe@', 'García');
});

test('optional absent invalid or oversized customer details are safely bounded', function (string $name, ?string $email, ?string $expectedName, ?string $expectedEmail): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->client->setFirstName($name)->setLastName(null);
    // Doctrine hydration can expose legacy values rejected by current native setters.
    (new ReflectionProperty(Box\Mod\Client\Entity\Client::class, 'email'))->setValue($ctx->client, $email);
    $ctx->em->flush();
    mollieResolveCustomer($ctx);
    expect($ctx->customerPayload['name'] ?? null)->toBe($expectedName)
        ->and($ctx->customerPayload['email'] ?? null)->toBe($expectedEmail)
        ->and($ctx->customerPayload)->not->toHaveKey('locale');
})->with([
    ['', null, null, null],
    ['  ', 'not-an-email', null, null],
    ['Customer', 'zoe@exämple.invalid', 'Customer', 'zoe@xn--exmple-cua.invalid'],
    ["bad\0name", ' valid@example.invalid ', null, 'valid@example.invalid'],
    [str_repeat('李', 300), str_repeat('a', 255) . '@example.invalid', str_repeat('李', 255), null],
]);

test('lost customer response replays encrypted original details after native profile changes', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->client->setFirstName('Original')->setLastName('Customer')->setEmail('original@example.invalid');
    $ctx->em->flush();
    $response = $ctx->customerResponses[CreateCustomerRequest::class];
    $ctx->customerResponses[CreateCustomerRequest::class] = static function ($pending) use ($response): never {
        $response($pending);
        throw new RuntimeException('Private original@example.invalid Original Customer');
    };
    try {
        mollieResolveCustomer($ctx);
        test()->fail('Expected lost response');
    } catch (Payment_Exception $error) {
        expect($error->getMessage())->not->toContain('Original', 'original@', 'Private')->and($error->getPrevious())->toBeNull();
    }
    $row = mollieCustomerRow($ctx);
    $payload = $ctx->customerPayload;
    $ctx->client->setFirstName('Changed')->setEmail('changed@example.invalid');
    $ctx->em->flush();
    $ctx->customerResponses[CreateCustomerRequest::class] = $response;
    mollieResolveCustomer($ctx);
    expect($ctx->customerPayload)->toBe($payload)
        ->and(mollieCustomerRow($ctx)['request'])->toBe($row['request'])
        ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
    $requests = count($ctx->customerRequests);
    mollieResolveCustomer($ctx);
    expect(count($ctx->customerRequests))->toBe($requests);
});

test('legacy metadata only customer reservations replay without adding personal fields', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $response = $ctx->customerResponses[CreateCustomerRequest::class];
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $legacy = json_encode(['metadata' => ['molliepayments_customer' => $row['id']]]);
    $ctx->db->update('molliepayments_customer', ['request' => $legacy], ['id' => $row['id']]);
    $ctx->customerResponses[CreateCustomerRequest::class] = $response;
    mollieResolveCustomer($ctx);
    expect($ctx->customerPayload)->toBe(json_decode($legacy, true))->and(mollieCustomerRow($ctx)['request'])->toBe($legacy);
});

test('native encryption protects durable customer details and rejects corruption or mapping swaps', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->client->setFirstName('Sensitive')->setLastName('Name')->setEmail('private@example.invalid');
    $crypt = new Crypt();
    $id = bin2hex(random_bytes(16));
    $snapshot = CustomerRequest::snapshot($ctx->client, $id, $crypt);
    expect($snapshot)->not->toContain('Sensitive', 'private@example.invalid')
        ->and(CustomerRequest::payload($snapshot, $id, $crypt)['name'])->toBe('Sensitive Name');
    $decoded = json_decode($snapshot, true);
    $decoded['metadata']['molliepayments_customer'] = str_repeat('f', 32);
    expect(fn () => CustomerRequest::payload(json_encode($decoded), str_repeat('f', 32), $crypt))->toThrow(Payment_Exception::class, 'could not be verified');
    $decoded['metadata']['molliepayments_customer'] = $id;
    $decoded['encrypted_details'] = 'corrupt';
    expect(fn () => CustomerRequest::payload(json_encode($decoded), $id, $crypt))->toThrow(Payment_Exception::class);
});

test('unreadable encrypted customer snapshot fails closed without replacement POST', function (): void {
    $ctx = mollieCustomers(molliePaymentContext());
    $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
    $row = mollieCustomerRow($ctx);
    $request = json_decode($row['request'], true);
    $request['encrypted_details'] = 'unreadable';
    $ctx->db->update('molliepayments_customer', ['request' => json_encode($request)], ['id' => $row['id']]);
    $before = count($ctx->customerRequests);
    expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class)
        ->and(count($ctx->customerRequests))->toBe($before + 1)
        ->and(mollieCustomerRow($ctx)['idempotency_key'])->toBe($row['idempotency_key']);
});

test('optional customer email follows native validation without changing the client identity', function (string $email): void {
    $ctx = mollieCustomers(molliePaymentContext());
    // Legacy hydration is allowed to contain details current native setters reject.
    (new ReflectionProperty(Box\Mod\Client\Entity\Client::class, 'email'))->setValue($ctx->client, $email);
    $snapshot = CustomerRequest::snapshot($ctx->client, str_repeat('a', 32), $ctx->di['crypt']);
    $payload = CustomerRequest::payload($snapshot, str_repeat('a', 32), $ctx->di['crypt']);
    $normalized = trim($email);
    $expected = strlen($normalized) <= 254 && FOSSBilling\Core\Validation\EmailValidator::isEmailValid($normalized) ? $normalized : null;
    expect($payload['email'] ?? null)->toBe($expected)
        ->and($ctx->client->getEmail())->toBe($email);
})->with([' foo&bar@example.invalid ', 'a+b@example.invalid', '"quoted"@example.invalid', "bad\n@example.invalid", 'missing-at', str_repeat('a', 64) . '@example.invalid', str_repeat('a', 65) . '@example.invalid']);
