<?php

declare(strict_types=1);

use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;

require_once __DIR__ . '/CustomerContext.php';

function mollieOnboarding(object $ctx): object
{
    mollieCustomers($ctx);
    $ctx->responses += $ctx->customerResponses;
    $ctx->mandateId = 'mdt_disposable';
    $ctx->mandateStatus = 'valid';
    $ctx->mandateScope = true;
    $ctx->mutateMandate = null;
    $ctx->mandateRequests = [];
    $ctx->responses[GetMandateRequest::class] = static function ($pending) use ($ctx) {
        $ctx->mandateRequests[] = $pending->createPsrRequest();
        expect($ctx->invoice->getStatus())->toBe('paid');

        return MockResponse::ok(mollieMandate($ctx));
    };
    $ctx->responses[GetPaginatedMandateRequest::class] = static function ($pending) use ($ctx) {
        $request = $pending->createPsrRequest();
        $ctx->mandateRequests[] = $request;
        parse_str($request->getUri()->getQuery(), $query);
        expect($query)->toBe(['from' => $ctx->mandateId, 'limit' => '1', 'scopes' => ['customer-not-present']]);

        return MockResponse::ok(['count' => $ctx->mandateScope ? 1 : 0,
            '_embedded' => ['mandates' => $ctx->mandateScope ? [mollieMandate($ctx)] : []],
            '_links' => ['self' => ['href' => (string) $request->getUri()]]]);
    };

    return $ctx;
}

function mollieMandate(object $ctx): array
{
    $result = ['resource' => 'mandate', 'id' => $ctx->mandateId, 'mode' => $ctx->gateway->isTestMode() ? 'test' : 'live',
        'method' => 'directdebit', 'status' => $ctx->mandateStatus,
        '_links' => ['customer' => ['href' => 'https://api.mollie.com/v2/customers/cst_disposable']]];

    return $ctx->mutateMandate !== null ? ($ctx->mutateMandate)($result) : $result;
}

function mollieFirst(object $ctx, bool $consent = true): ?string
{
    return $ctx->payments->firstCheckout((int) $ctx->invoice->getId(), $consent);
}
