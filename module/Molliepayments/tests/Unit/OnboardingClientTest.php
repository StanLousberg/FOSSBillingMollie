<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Api\Client as ClientApi;
use Box\Mod\Molliepayments\PaymentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once dirname(__DIR__) . '/OnboardingContext.php';

function mollieClientApi(object $ctx, array $params = [], string $method = 'POST'): ClientApi
{
    $ctx->di['request'] = Request::create('/api/client/molliepayments/onboarding_start', $method, $params);
    $session = new Session(new MockArraySessionStorage());
    $session->set('csrf_token', 'disposable-nonce');
    $ctx->di['session'] = $session;
    $api = new ClientApi();
    $api->setDi($ctx->di);
    $api->setIdentity($ctx->client);
    $api->setService($ctx->services['molliepayments']);

    return $api;
}

function mollieConsentParams(object $ctx): array
{
    return ['CSRFToken' => 'disposable-nonce', 'consent' => '1', 'consent_digest' => $ctx->payments->onboarding($ctx->invoice->getId())['consent_digest'], 'consent_version' => PaymentService::CONSENT_VERSION, 'invoice_id' => $ctx->invoice->getId()];
}

test('client API accepts the native session nonce after the dispatcher strips it from parameters', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $params = mollieConsentParams($ctx);
    $api = mollieClientApi($ctx, $params);
    unset($params['CSRFToken']);
    $params += ['customer_id' => 'cst_attacker', 'gateway_id' => 999, 'environment' => 'live', 'amount' => '0.01', 'client_id' => 999];
    expect($api->onboarding_start($params)['checkout_url'])->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['customerId'])->toBe('cst_disposable')->and($ctx->payload['amount']['value'])->toBe('12.34')
        ->and($ctx->payload['metadata']['environment'])->toBe('test');
});

test('client API rejects unsafe writes before any customer or payment request', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $params = mollieConsentParams($ctx);
    match ($case) {
        'csrf_missing' => $params['CSRFToken'] = null,
        'csrf_wrong' => $params['CSRFToken'] = 'wrong',
        'csrf_array' => $params['CSRFToken'] = ['disposable-nonce'],
        'consent_missing' => $params['consent'] = null,
        'consent_false' => $params['consent'] = false,
        'consent_version' => $params['consent_version'] = 'old-text',
        'invoice' => $params['invoice_id'] = ['1'],
        default => null,
    };
    $api = mollieClientApi($ctx, $params, $case === 'get' ? 'GET' : 'POST');
    if ($case === 'guest') {
        $api->setIdentity(new FOSSBilling\Core\Identity\Guest());
    } elseif ($case === 'cookie') {
        $ctx->di['request']->cookies->set(FOSSBilling\Core\Http\CookieNames::CSRF, 'wrong');
    } elseif ($case === 'owner') {
        $ctx->invoice->setClientId(999);
        $ctx->em->flush();
    }
    expect(fn () => $api->onboarding_start($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
    expect(fn () => $api->onboarding_verify($params))->toThrow(Payment_Exception::class);
    if (!in_array($case, ['consent_missing', 'consent_false', 'consent_version'], true)) {
        expect(fn () => $api->checkout($params))->toThrow(Payment_Exception::class);
    }
})->with(['csrf_missing', 'csrf_wrong', 'csrf_array', 'cookie', 'get', 'guest', 'consent_missing', 'consent_false', 'consent_version', 'invoice', 'owner']);

test('client module route exposes only owned invoice onboarding data', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->di['is_client_logged'] = true;
    $controller = new Box\Mod\Molliepayments\Controller\Client();
    $controller->setDi($ctx->di);
    $app = Mockery::mock(FOSSBilling\Core\Http\App::class);
    $app->shouldReceive('get')->once()->with('/molliepayments/onboarding/:invoice', 'get_onboarding', ['invoice' => '[0-9]+'], $controller::class);
    $controller->register($app);
    $app->shouldReceive('render')->once()->with('mod_molliepayments_onboarding', ['onboarding' => $ctx->payments->onboarding($ctx->invoice->getId())])->andReturn('Owned onboarding page');
    expect($controller->get_onboarding($app, $ctx->invoice->getId()))->toBe('Owned onboarding page');
    $ctx->invoice->setClientId(999);
    $ctx->em->flush();
    expect(fn () => $controller->get_onboarding($app, $ctx->invoice->getId()))->toThrow(Payment_Exception::class, 'owner authentication');
});

test('ordinary gateway checkout needs no recurring consent for an authenticated owner', function (): void {
    $ctx = molliePaymentContext();
    $adapter = $ctx->gatewayService->getPaymentAdapter($ctx->gateway, $ctx->invoice);
    $html = $adapter->getHtml(new stdClass(), $ctx->invoice->getId(), false);
    expect($html)->toContain('window.location.assign', 'https://www.mollie.com/checkout/')
        ->not->toContain($ctx->key, '/molliepayments/onboarding/', 'consent')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')
        ->and($ctx->customerRequests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT consent_version FROM molliepayments_attempt'))->toBeNull();
});

test('client can pay once without consent and cannot override saved gateway context', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $params = ['CSRFToken' => 'disposable-nonce', 'invoice_id' => $ctx->invoice->getId(), 'gateway_id' => 999, 'environment' => 'live'];
    $api = mollieClientApi($ctx, $params);
    expect($api->checkout($params)['checkout_url'])->toContain('https://www.mollie.com/checkout/')
        ->and($ctx->payload['sequenceType'])->toBe('oneoff')
        ->and($ctx->payload['metadata']['environment'])->toBe('test')
        ->and($ctx->customerRequests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT consent_version FROM molliepayments_attempt'))->toBeNull();
});

test('client one-time choice rejects ambiguous gateway configuration before provider calls', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $duplicate = clone $ctx->gateway;
    $ctx->em->persist($duplicate);
    $ctx->em->flush();
    $params = ['CSRFToken' => 'disposable-nonce', 'invoice_id' => $ctx->invoice->getId()];
    $api = mollieClientApi($ctx, $params);
    expect(fn () => $api->checkout($params))->toThrow(Payment_Exception::class, 'Exactly one')
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});

function mollieRenderOnboarding(array $data = []): string
{
    $module = dirname(__DIR__, 2);
    $di = Tests\Helpers\container();
    $twig = new Twig\Environment(new Twig\Loader\ChainLoader([
        new Twig\Loader\FilesystemLoader($module . '/templates/client'),
        new Twig\Loader\ArrayLoader(['layout_default.html.twig' => '{% block content %}{% endblock %}']),
    ]), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addExtension(new Twig\Extension\AttributeExtension(FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class));
    $twig->addExtension(new Twig\Extension\AttributeExtension(FOSSBilling\Core\Twig\Extension\ApiExtension::class));
    $twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([
        FOSSBilling\Core\Twig\Extension\ApiExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\ApiExtension($di),
        FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\FOSSBillingExtension($di),
    ]));
    $twig->addGlobal('app_area', 'client');

    return $twig->render('mod_molliepayments_onboarding.html.twig', ['CSRFToken' => 'disposable-nonce',
        'onboarding' => $data + ['order_id' => '7', 'order_title' => 'Recurring service', 'period_title' => 'Every month', 'amount' => '12.34', 'currency' => 'EUR', 'can_resume' => false, 'can_pay' => true, 'can_verify' => true, 'consent_digest' => 'disposable-digest', 'invoice_id' => 1, 'invoice_hash' => 'disposable', 'status' => 'not_started', 'consent_version' => PaymentService::CONSENT_VERSION, 'consent_text' => PaymentService::CONSENT_TEXT]]);
}

test('client template renders native filters CSRF and an unchecked explicit consent control', function (): void {
    $html = mollieRenderOnboarding();
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//input[@type="checkbox" and @name="consent" and not(@checked) and @required]')->length)->toBe(1)
        ->and($xpath->query('//style | //*[@style] | //link[@rel="stylesheet"]')->length)->toBe(0)
        ->and($html)->toContain('col-12 col-lg-8', 'form-check-input', 'role="status"', 'aria-live="polite"', 'buttons.forEach', 'if (busy) return', 'Recurring service', '12.34 EUR')
        ->and($html)->toContain('/api/client/molliepayments/onboarding_start', '/api/client/molliepayments/onboarding_verify', '/api/client/molliepayments/checkout', 'Pay once without future authorization', 'disposable-nonce', 'actual invoices', '/invoice/disposable');
});

test('client template fallback supports symlinked installations and native theme precedence', function (): void {
    $di = Tests\Helpers\container();
    $loader = new Twig\Loader\ArrayLoader([]);
    $twig = new Twig\Environment($loader);
    $factory = Mockery::mock(FOSSBilling\Core\Twig\TwigFactory::class);
    $factory->shouldReceive('createClientEnvironment')->twice()->andReturn($twig);
    $di['twig_factory'] = $factory;
    Box\Mod\Molliepayments\Support\AdminTwigFactory::register($di);
    $registered = $di['twig_factory'];
    expect($registered->createClientEnvironment(new DebugBar\StandardDebugBar())->getLoader()->exists('mod_molliepayments_onboarding.html.twig'))->toBeTrue();
    $loader->setTemplate('mod_molliepayments_onboarding.html.twig', 'Client theme override');
    expect($registered->createClientEnvironment(new DebugBar\StandardDebugBar())->render('mod_molliepayments_onboarding.html.twig'))->toBe('Client theme override');
});

test('direct API refuses ineligible or changed displayed authorization before provider activity', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $params = mollieConsentParams($ctx);
    if ($case === 'custom') {
        $ctx->item->setType(Box\Mod\Invoice\Entity\InvoiceItem::TYPE_CUSTOM);
    } elseif ($case === 'changed') {
        $ctx->order->setTitle('Changed order authorization');
    } else {
        $params['consent_digest'] = 'tampered';
    }
    $ctx->em->flush();
    $api = mollieClientApi($ctx, $params);
    expect(fn () => $api->onboarding_start($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['custom', 'changed', 'digest']);

test('client UI translates payment states and only offers appropriate actions', function (string $state, bool $pay, string $message): void {
    $html = mollieRenderOnboarding(['status' => $state, 'can_pay' => $pay, 'can_verify' => !$pay]);
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($html)->toContain($message)->not->toContain('Onboarding status:', '>pending_mandate<', '>manual_review<')
        ->and($xpath->query('//form[@id="molliepayments-onboarding"]')->length)->toBe($pay ? 1 : 0)
        ->and($xpath->query('//form[@id="molliepayments-oneoff"]')->length)->toBe($pay ? 1 : 0);
})->with([
    ['pending_payment', false, 'first payment has already started'],
    ['paid', false, 'No further payment is needed'],
    ['ready', false, 'suitable mandate has been verified'],
    ['pending_mandate', false, 'still checking'],
    ['payment_failed', true, 'choose how to try again'],
    ['manual_review', false, 'support review'],
    ['mandate_invalid', false, 'mandate is not valid'],
]);
