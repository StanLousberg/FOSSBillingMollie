<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\CustomerService;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\GetCurrentProfileRequest;
use Mollie\Api\Http\Requests\GetCustomerRequest;
use Mollie\Api\MollieApiClient;

require_once __DIR__ . '/PaymentContext.php';

function mollieCustomers(object $ctx): object
{
    $ctx->di['loggedin_client'] = $ctx->client;
    $ctx->customerRequests = [];
    $ctx->profileId = 'pfl_disposable';
    $ctx->customerResponse = null;
    $ctx->customerResponses = [
        GetCurrentProfileRequest::class => static function ($pending) use ($ctx) {
            $ctx->customerRequests[] = $pending->createPsrRequest();

            return MockResponse::ok(['resource' => 'profile', 'id' => $ctx->profileId]);
        },
        CreateCustomerRequest::class => static function ($pending) use ($ctx) {
            $request = $pending->createPsrRequest();
            $ctx->customerRequests[] = $request;
            $ctx->customerPayload = json_decode((string) $request->getBody(), true);
            $row = mollieCustomerRow($ctx);
            // Reservation and lease must be visible before any provider POST.
            expect($row['status'])->toBe('creating')->and($row['lease'])->not->toBeNull()
                ->and($request->getHeaderLine('Idempotency-Key'))->toBe($row['idempotency_key'])
                ->and($ctx->customerPayload)->toEqual(Box\Mod\Molliepayments\Support\CustomerRequest::payload($row['request'], $row['id'], $ctx->di['crypt']));

            return MockResponse::created(mollieCustomerResponse($ctx));
        },
        GetCustomerRequest::class => static function ($pending) use ($ctx) {
            $ctx->customerRequests[] = $pending->createPsrRequest();

            return MockResponse::ok(mollieCustomerResponse($ctx));
        },
    ];
    $ctx->customers = new CustomerService($ctx->di, static fn (string $key) => MollieApiClient::fake($ctx->customerResponses)->setApiKey($key));

    return $ctx;
}

function mollieCustomerResponse(object $ctx): array
{
    $row = mollieCustomerRow($ctx);

    return $ctx->customerResponse ?? ['resource' => 'customer', 'id' => 'cst_disposable',
        'mode' => $ctx->gateway->isTestMode() ? 'test' : 'live', 'metadata' => json_decode($row['request'], true)['metadata']];
}

function mollieCustomerRow(object $ctx): array|false
{
    return $ctx->db->fetchAssociative('SELECT * FROM molliepayments_customer WHERE client_id = ? AND gateway_id = ? AND environment = ?',
        [$ctx->client->getId(), $ctx->gateway->getId(), $ctx->gateway->isTestMode() ? 'test' : 'live']);
}

function mollieResolveCustomer(object $ctx, bool $create = true, bool $verify = false): ?string
{
    return $ctx->customers->resolve($ctx->client->getId(), $ctx->gateway->getId(), $create, $verify);
}
