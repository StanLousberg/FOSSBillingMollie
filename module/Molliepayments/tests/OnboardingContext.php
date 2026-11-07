<?php

declare(strict_types=1);

use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;

require_once __DIR__ . '/CustomerContext.php';

function mollieOnboarding(object $ctx): object
{
    $order = $ctx->em->find(Box\Mod\Order\Entity\Order::class, (int) $ctx->item->getRelId());
    if ($order === null) {
        $order = new Box\Mod\Order\Entity\Order();
        $order->setClientId($ctx->client->getId())->setTitle('Disposable recurring service')->setPeriod('1M')->setStatus('pending_setup');
        $ctx->em->persist($order);
        $ctx->em->flush();
        $ctx->item->setType(Box\Mod\Invoice\Entity\InvoiceItem::TYPE_ORDER)->setRelId((string) $order->getId())->setPeriod('1M');
        $ctx->em->flush();
    }
    $order->setPrice('20.00')->setQuantity(1)->setCurrency('EUR');
    $ctx->em->flush();
    $ctx->services['system']->shouldReceive('getCompany')->byDefault()->andReturn(['name' => 'Example Hosting']);
    $product = new Box\Mod\Product\Entity\Product();
    $product->setType('custom');
    $ctx->services['product']->shouldReceive('findProductById')->byDefault()->andReturn($product);
    $ctx->services['product']->shouldReceive('getRenewalPromoAdjustments')->byDefault()->andReturn([]);
    $ctx->order = $order;
    $orderService = new Box\Mod\Order\Service();
    $orderService->setDi($ctx->di);
    $ctx->services['order'] = $orderService;
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
