<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\ConnectionChecker;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Box\Mod\Molliepayments\Support\SdkUnavailableException;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Exceptions\RequestTimeoutException;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentsRequest;
use Mollie\Api\MollieApiClient;

require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';
MollieClientFactory::bootSdk();

require_once dirname(__DIR__) . '/Support.php';

test('connection uses exactly one read-only request with the selected saved key', function (string $environment, bool $empty): void {
    $keys = ['api_key' => mollieDummyKey('live'), 'test_api_key' => mollieDummyKey('test')];
    $expectedKey = $keys[$environment === 'test' ? 'test_api_key' : 'api_key'];
    $payments = $empty ? [] : [['resource' => 'payment', 'id' => 'tr_disposable', 'description' => 'Private payment details']];
    $client = MollieApiClient::fake([
        GetPaginatedPaymentsRequest::class => MockResponse::ok([
            'count' => count($payments), '_embedded' => ['payments' => $payments],
            '_links' => ['self' => ['href' => 'https://api.mollie.com/v2/payments?limit=1']],
        ]),
    ]);
    $factory = function (string $key) use ($client, $expectedKey): MollieApiClient {
        expect($key)->toBe($expectedKey);

        return $client->setApiKey($key);
    };
    $adapter = new Payment_Adapter_MolliePayments($keys + ['gateway_id' => 1, 'test_mode' => $environment !== 'test']);
    $result = (new ConnectionChecker($factory))->check($adapter, $environment);

    expect($result)->toBe(['success' => true, 'environment' => $environment, 'message' => 'Successfully connected to Mollie.'])
        ->and($adapter->getParam('test_mode'))->toBe($environment !== 'test');
    $client->assertSentCount(1);
    $client->assertSent(function (PendingRequest $pending) use ($expectedKey): bool {
        $request = $pending->createPsrRequest();
        parse_str($request->getUri()->getQuery(), $query);
        expect($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getScheme())->toBe('https')
            ->and($request->getUri()->getHost())->toBe('api.mollie.com')
            ->and($request->getUri()->getPath())->toBe('/v2/payments')
            ->and($query)->toBe(['limit' => '1'])
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ' . $expectedKey)
            ->and((string) $request->getBody())->toBe('');

        return true;
    });
})->with(['test', 'live'])->with([true, false]);

test('provider failures produce sanitized feedback', function (int $status, string $expected): void {
    $key = mollieDummyKey('test');
    $client = MollieApiClient::fake([
        GetPaginatedPaymentsRequest::class => MockResponse::error($status, 'Provider failure', 'Secret ' . $key . ' and private payment data'),
    ]);
    $checker = new ConnectionChecker(fn (string $saved) => $client->setApiKey($saved));
    $result = $checker->check(new Payment_Adapter_MolliePayments(['gateway_id' => 1, 'test_api_key' => $key]), 'test');

    expect($result['success'])->toBeFalse()
        ->and($result['environment'])->toBe('test')
        ->and($result['message'])->toContain($expected)
        ->and(json_encode($result))->not->toContain($key, 'private payment data', 'Provider failure', 'Authorization');
    $client->assertSentCount(1);
})->with([
    [401, 'rejected'], [403, 'permission'], [408, 'timed out'], [429, 'rate limit'],
    [500, 'temporarily unavailable'], [502, 'temporarily unavailable'], [503, 'temporarily unavailable'],
    [504, 'timed out'], [422, 'could not confirm'],
]);

test('network and timeout exceptions remain private', function (string $exceptionClass, string $expected): void {
    $key = mollieDummyKey('test');
    $client = MollieApiClient::fake();
    $pending = new PendingRequest($client, new GetPaginatedPaymentsRequest());
    $checker = new ConnectionChecker(function () use ($exceptionClass, $pending, $key): never {
        throw new $exceptionClass($pending, null, 'Authorization: Bearer ' . $key);
    });
    $result = $checker->check(new Payment_Adapter_MolliePayments(['gateway_id' => 1, 'test_api_key' => $key]), 'test');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain($expected)
        ->and(json_encode($result))->not->toContain($key, 'Bearer');
    $client->assertSentCount(0);
})->with([[RequestTimeoutException::class, 'timed out'], [NetworkRequestException::class, 'Could not connect']]);

test('missing or mismatched credentials never construct a client', function (mixed $key, string $expected): void {
    $checker = new ConnectionChecker(function (): never {
        throw new LogicException('The client factory must not be called.');
    });
    $result = $checker->check(new Payment_Adapter_MolliePayments(['gateway_id' => 1, 'test_api_key' => $key]), 'test');
    expect($result['success'])->toBeFalse()->and($result['message'])->toContain($expected);
})->with([[null, 'No saved Test API key'], ['', 'No saved Test API key'], ['live_wrong-environment', 'Invalid Test API key'], [['invalid'], 'Invalid Test API key']]);

test('SDK authentication validation and unexpected errors are sanitized', function (string $failure): void {
    $key = mollieDummyKey('live');
    $checker = new ConnectionChecker(function () use ($failure, $key): never {
        throw match ($failure) {
            'sdk' => new SdkUnavailableException('Sensitive ' . $key),
            'authentication' => new Mollie\Api\Exceptions\InvalidAuthenticationException($key),
            default => new RuntimeException('Sensitive ' . $key),
        };
    });
    $result = $checker->check(new Payment_Adapter_MolliePayments(['gateway_id' => 1, 'api_key' => $key]), 'live');
    expect($result['success'])->toBeFalse()->and(json_encode($result))->not->toContain($key, 'Sensitive');
})->with(['sdk', 'authentication', 'unexpected']);

test('unsupported environments are rejected before reading credentials', function (): void {
    $adapter = new Payment_Adapter_MolliePayments(['gateway_id' => 1]);
    expect(fn () => (new ConnectionChecker())->check($adapter, 'production'))
        ->toThrow(InvalidArgumentException::class, 'Select either');
});
