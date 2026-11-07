<?php

declare(strict_types=1);

use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;

require_once __DIR__ . '/OnboardingContext.php';

/** A method-specific full instrument identifier, never just a card's last four digits. */
function mollieDiscoverableMandate(object $ctx, string $method = 'ideal'): object
{
    $ctx->mandateId = null;
    $expected = match ($method) {
        'creditcard' => 'creditcard', 'paypal' => 'paypal', default => 'directdebit'
    };
    $details = match ($expected) {
        'creditcard' => ['cardFingerprint' => 'disposable-test-fingerprint'],
        'paypal' => ['consumerAccount' => 'disposable@example.invalid'],
        default => ['consumerAccount' => 'NL00TEST0000000000'],
    };
    $ctx->mutatePayment = static function (array $payment) use ($method, $details): array {
        $payment['method'] = $method;
        $payment['createdAt'] = gmdate(DATE_ATOM, time() - 120);
        $payment['details'] = $details;

        return $payment;
    };
    $ctx->boundMandate = ['resource' => 'mandate', 'id' => 'mdt_bound', 'mode' => 'test', 'method' => $expected,
        'customerId' => 'cst_disposable', 'status' => 'valid', 'createdAt' => gmdate(DATE_ATOM, time() - 60),
        'details' => $details, '_links' => ['customer' => ['href' => 'https://api.mollie.com/v2/customers/cst_disposable']]];
    $ctx->discoveryMandates = [$ctx->boundMandate];
    $ctx->discoveryNext = false;
    $ctx->responses[GetMandateRequest::class] = static function ($pending) use ($ctx) {
        $ctx->mandateRequests[] = $pending->createPsrRequest();

        return MockResponse::ok($ctx->boundMandate);
    };
    $ctx->responses[GetPaginatedMandateRequest::class] = static function ($pending) use ($ctx) {
        $request = $pending->createPsrRequest();
        $ctx->mandateRequests[] = $request;
        parse_str($request->getUri()->getQuery(), $query);
        expect($query['scopes'])->toBe(['customer-not-present']);
        $items = isset($query['from']) ? ($ctx->mandateScope ? [$ctx->boundMandate] : []) : $ctx->discoveryMandates;
        expect($query['limit'])->toBe(isset($query['from']) ? '1' : '250');

        return MockResponse::ok(['count' => count($items), '_embedded' => ['mandates' => $items],
            '_links' => ['self' => ['href' => (string) $request->getUri()]] + ($ctx->discoveryNext ? ['next' => ['href' => 'https://api.mollie.com/v2/customers/cst_disposable/mandates?from=mdt_more']] : [])]);
    };

    $ctx->payments = new Box\Mod\Molliepayments\PaymentService($ctx->di, static function (string $key) use ($ctx) {
        expect($key)->toBe($ctx->key);

        return Mollie\Api\MollieApiClient::fake($ctx->responses, retainRequests: true)->setApiKey($key);
    });

    return $ctx;
}
