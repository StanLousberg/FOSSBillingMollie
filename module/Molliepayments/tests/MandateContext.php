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
    $ctx->paymentMandateId = null;
    $expected = match ($method) {
        'creditcard' => 'creditcard', 'paypal' => 'paypal', default => 'directdebit',
    };
    $details = match ($expected) {
        'creditcard' => ['cardFingerprint' => 'disposable-test-fingerprint'],
        'paypal' => ['consumerAccount' => 'disposable@example.invalid'],
        default => ['consumerAccount' => 'NL00TEST0000000000'],
    };
    $ctx->mutatePayment = static function (array $payment) use ($ctx, $method, $details): array {
        $payment['method'] = $method;
        $payment['mandateId'] = $ctx->paymentMandateId;
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

/** Another native invoice for the same recurring order, with no financial fixture shortcuts. */
function mollieAdditionalRecurringInvoice(object $ctx): void
{
    $invoice = new Box\Mod\Invoice\Entity\Invoice();
    $invoice->setClientId($ctx->client->getId())->setCurrency('EUR')->setIssued(true)->setHash(bin2hex(random_bytes(20)))->setTaxrate(0);
    $ctx->em->persist($invoice);
    $ctx->em->flush();
    $item = new Box\Mod\Invoice\Entity\InvoiceItem();
    $item->setInvoice($invoice)->setTitle('Disposable overlapping first payment')->setType('order')->setRelId((string) $ctx->order->getId())->setPeriod('1M')->setQuantity(1)->setPrice('12.34');
    $ctx->em->persist($item);
    $ctx->em->flush();
    $ctx->invoice = $invoice;
    $ctx->item = $item;
}

function mollieFinancialSnapshot(object $ctx): array
{
    return ['transactions' => $ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteSingleIdentifier('transaction') . ' ORDER BY id'),
        'ledger' => $ctx->db->fetchAllAssociative('SELECT * FROM client_balance ORDER BY id'),
        'invoice' => $ctx->db->fetchAllAssociative('SELECT * FROM invoice ORDER BY id')];
}
