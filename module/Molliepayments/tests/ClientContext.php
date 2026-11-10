<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Api\Client as ClientApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once __DIR__ . '/OnboardingContext.php';

function mollieClientApi(object $ctx, array $params = [], string $method = 'POST', string $action = 'onboarding_start'): ClientApi
{
    $ctx->di['request'] = Request::create('/api/client/molliepayments/' . $action, $method, $params);
    $session = new Session(new MockArraySessionStorage());
    $session->set('csrf_token', 'disposable-nonce');
    $ctx->di['session'] = $session;
    $api = new ClientApi();
    $api->setDi($ctx->di);
    $api->setIdentity($ctx->client);
    $api->setService($ctx->services['molliepayments']);

    return $api;
}

function mollieSetPaymentMode(object $ctx, string $mode): void
{
    $config = mollieGatewayConfig($ctx->gateway);
    $config['payment_mode'] = $mode;
    $ctx->gateway->setConfig(mollieGatewayValue('config', $config));
    $ctx->em->flush();
}

function mollieCartParams(object $ctx): array
{
    return $ctx->payments->consentNotice($ctx->invoice->getId()) + ['CSRFToken' => 'disposable-nonce', 'consent' => '1', 'invoice_hash' => $ctx->invoice->getHash()];
}
