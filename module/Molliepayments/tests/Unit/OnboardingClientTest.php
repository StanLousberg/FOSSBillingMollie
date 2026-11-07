<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\PaymentService;

require_once dirname(__DIR__) . '/ClientContext.php';

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
        'consent_version' => $params['consent_version'] = 'order-collection-v2',
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
        'onboarding' => $data + ['payment_mode' => 'both', 'can_authorize' => true, 'can_pay_once' => true, 'company_name' => 'Example Hosting', 'recurring_amount' => '20.00', 'recurring_currency' => 'EUR', 'order_id' => '7', 'order_title' => 'Recurring service', 'period_title' => 'Every month', 'amount' => '12.34', 'currency' => 'EUR', 'can_resume' => false, 'can_pay' => true, 'can_verify' => true, 'consent_digest' => 'disposable-digest', 'invoice_id' => 1, 'invoice_hash' => 'disposable', 'status' => 'not_started', 'consent_version' => PaymentService::CONSENT_VERSION, 'consent_text' => sprintf(PaymentService::CONSENT_TEXT, 'Example Hosting')]]);
}

test('client template renders native filters CSRF and an unchecked explicit consent control', function (): void {
    $html = mollieRenderOnboarding();
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//input[@type="checkbox" and @name="consent" and not(@checked) and @required and @form="molliepayments-onboarding"]')->length)->toBe(1)
        ->and($xpath->query('//label[@for="molliepayments-consent"]')->length)->toBe(1)
        ->and($xpath->query('//style | //*[@style] | //link[@rel="stylesheet"]')->length)->toBe(0)
        ->and($xpath->evaluate('normalize-space(//dl/dd[last()])'))->toBe('12.34 EUR including tax')
        ->and($xpath->evaluate('normalize-space(//form[@id="molliepayments-oneoff"]//button)'))->toBe('Pay once')
        ->and($xpath->evaluate('normalize-space(//form[@id="molliepayments-onboarding"]//button)'))->toBe('Authorize and pay')
        ->and($html)->toContain('form-check-input', 'role="status"', 'aria-live="polite"', 'buttons.forEach', 'if (busy) return', 'Recurring service', '/invoice/disposable');
    foreach (['onboarding' => 'onboarding_start', 'oneoff' => 'checkout', 'verify' => 'onboarding_verify'] as $id => $action) {
        $form = '//form[@id="molliepayments-' . $id . '" and @method="post"]';
        expect($xpath->query($form)->length)->toBe(1)
            ->and($xpath->evaluate('string(' . $form . '/@action)'))->toBe(Tests\Helpers\container()['url']->link('api/client/molliepayments/' . $action))
            ->and($xpath->query($form . '/input[@name="CSRFToken" and @value="disposable-nonce"]')->length)->toBe(1)
            ->and($xpath->query($form . '/input[@name="invoice_id" and @value="1"]')->length)->toBe(1);
    }
    expect($xpath->query('//form[@id="molliepayments-onboarding"]/input[@name="consent_digest" and @value="disposable-digest"]')->length)->toBe(1)
        ->and($xpath->query('//form[@id="molliepayments-onboarding"]/input[@name="consent_version" and @value="' . PaymentService::CONSENT_VERSION . '"]')->length)->toBe(1);
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
    } elseif ($case === 'company') {
        $ctx->services['system']->shouldReceive('getCompany')->andReturn(['name' => 'Changed Hosting']);
    } elseif ($case === 'price') {
        $ctx->order->setPrice('99.00');
    } elseif ($case === 'period') {
        $ctx->order->setPeriod('1Y');
        $ctx->item->setPeriod('1Y');
    } elseif ($case === 'amount') {
        $ctx->item->setPrice('99.00');
    } else {
        $params['consent_digest'] = 'tampered';
    }
    $ctx->em->flush();
    $api = mollieClientApi($ctx, $params);
    expect(fn () => $api->onboarding_start($params))->toThrow(Payment_Exception::class)
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
})->with(['custom', 'changed', 'company', 'price', 'period', 'amount', 'digest']);

test('client UI translates payment states and only offers appropriate actions', function (string $state, bool $pay, string $message): void {
    $html = mollieRenderOnboarding(['status' => $state, 'can_pay' => $pay, 'can_verify' => !$pay]);
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('normalize-space(//div[@role="status"])'))->toBe($message)
        ->and($html)->not->toContain('Onboarding status:', '>pending_mandate<', '>manual_review<')
        ->and($xpath->query('//form[@id="molliepayments-onboarding"]')->length)->toBe($pay ? 1 : 0)
        ->and($xpath->query('//form[@id="molliepayments-oneoff"]')->length)->toBe($pay ? 1 : 0);
})->with([
    ['pending_payment', false, 'A payment has already started. Please check the existing payment before continuing.'],
    ['paid', false, 'This invoice has been paid. No further payment is needed.'],
    ['ready', false, 'Your payment and authorization have been verified.'],
    ['pending_mandate', false, 'Your payment is complete. We are verifying your payment authorization.'],
    ['payment_failed', true, 'Your previous payment was unsuccessful. You can try again.'],
    ['manual_review', false, 'This payment requires review. Please contact support before trying again.'],
    ['mandate_invalid', false, 'Your payment is complete, but authorization could not be verified. Please contact support.'],
]);

test('authorization page retrieves the native company name and escapes it in consent and copy', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->services['system']->shouldReceive('getCompany')->once()->andReturn(['name' => 'Hosting & <Company>']);
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    expect($page['company_name'])->toBe('Hosting & <Company>')
        ->and($page['consent_text'])->toContain('I authorize Hosting & <Company> to collect future renewal payments');
    $html = mollieRenderOnboarding($page);
    expect($html)->toContain('Hosting &amp; &lt;Company&gt;')->not->toContain('<Company>');
});

test('renewal summary uses native quantity and renewal discounts independently of taxed first payment', function (string $period): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->order->setPrice('30.00')->setQuantity(3)->setPeriod($period);
    $ctx->item->setPeriod($period)->setPrice('10.00')->setQuantity(2)->setTaxed(true);
    $ctx->invoice->setTaxrate(21);
    $ctx->em->flush();
    $ctx->services['product']->shouldReceive('getRenewalPromoAdjustments')->once()->with($ctx->order, 30.0, 3.0)
        ->andReturn([['discount_amount' => 9.0], ['discount_amount' => 6.0]]);
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    expect($page['recurring_amount'])->toBe('75.00')->and($page['recurring_currency'])->toBe('EUR')
        ->and($page['amount'])->toBe('24.20')->and($page['period'])->toBe($period)
        ->and($page['period_title'])->toBe((new FOSSBilling\Core\Period($period))->getTitle())
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty()
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
    $document = new DOMDocument();
    @$document->loadHTML(mollieRenderOnboarding($page));
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('normalize-space(//dl/dd[last()])'))->toBe('24.20 EUR including tax');
})->with(['1M', '3M', '1Y', '2Y']);

test('renewal summary respects native renewal line overrides instead of initial order price', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $ctx->invoiceService->shouldReceive('resolveRenewalLine')->once()->with($ctx->order)
        ->andReturn(['price' => 65.0, 'line' => ['price' => 65.0, 'quantity' => 2]]);
    $ctx->services['product']->shouldReceive('getRenewalPromoAdjustments')->once()->with($ctx->order, 65.0, 2.0)->andReturn([]);
    expect($ctx->payments->onboarding($ctx->invoice->getId())['recurring_amount'])->toBe('130.00');
});

test('authorization markup stacks details on small screens and keeps only essential status feedback', function (): void {
    $html = mollieRenderOnboarding(['can_verify' => false]);
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//dl[contains(@class,"row")]/dt[contains(@class,"col-sm-4")]')->length)->toBe(3)
        ->and($xpath->query('//dl/dd[contains(@class,"col-sm-8")]')->length)->toBe(3)
        ->and($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," alert ")]')->length)->toBe(0)
        ->and($xpath->query('//ol | //style | //*[@style]')->length)->toBe(0)
        ->and($html)->not->toContain('justify-content-center', 'text-center', 'How authorization works')
        ->and($xpath->query('//div[contains(@class,"col-12") and contains(@class,"col-md-6")]/form')->length)->toBe(2)
        ->and($xpath->query('//form[@id="molliepayments-oneoff"]//button[contains(@class,"btn-outline-secondary")]')->length)->toBe(1)
        ->and($xpath->query('//form[@id="molliepayments-onboarding"]//button[contains(@class,"btn-primary")]')->length)->toBe(1);
});

test('rendered actions follow actual persisted payment state', function (string $state, bool $pay, bool $resume, bool $verify): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    if ($state === 'existing_payment') {
        $ctx->payments->oneTimeCheckout($ctx->invoice->getId());
    } elseif ($state === 'paid') {
        $ctx->invoice->setStatus('paid');
        $ctx->em->flush();
    } elseif ($state !== 'not_started') {
        mollieFirst($ctx);
        if ($state === 'manual_review') {
            $ctx->db->update('molliepayments_attempt', ['error' => 'requires_review'], ['id' => mollieAttempt($ctx)['id']]);
        } elseif ($state !== 'pending_payment') {
            $ctx->status = $state === 'payment_failed' ? 'failed' : 'paid';
            if ($state === 'pending_mandate') {
                $ctx->mandateStatus = 'pending';
            } elseif ($state === 'mandate_invalid') {
                $ctx->mandateStatus = 'invalid';
            }
            mollieWebhook($ctx);
        }
    }
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    expect($page['status'])->toBe($state);
    $document = new DOMDocument();
    @$document->loadHTML(mollieRenderOnboarding($page));
    $xpath = new DOMXPath($document);
    foreach (['onboarding' => $pay, 'oneoff' => $pay, 'resume' => $resume, 'verify' => $verify] as $action => $visible) {
        expect($xpath->query('//form[@id="molliepayments-' . $action . '"]')->length)->toBe($visible ? 1 : 0);
    }
})->with([
    ['not_started', true, false, false], ['pending_payment', false, true, true],
    ['existing_payment', false, true, false], ['paid', false, false, false],
    ['payment_failed', true, false, true], ['pending_mandate', false, false, true],
    ['ready', false, false, true], ['mandate_invalid', false, false, true], ['manual_review', false, false, true],
]);

test('onboarding offers only actions allowed by the saved payment mode', function (string $mode, bool $first, bool $oneoff): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieSetPaymentMode($ctx, $mode);
    $page = $ctx->payments->onboarding($ctx->invoice->getId());
    $document = new DOMDocument();
    @$document->loadHTML(mollieRenderOnboarding($page));
    $xpath = new DOMXPath($document);
    expect($page['payment_mode'])->toBe($mode)
        ->and($xpath->query('//form[@id="molliepayments-onboarding"]')->length)->toBe($first ? 1 : 0)
        ->and($xpath->query('//input[@name="consent" and @required and not(@checked)]')->length)->toBe($first ? 1 : 0)
        ->and($xpath->query('//form[@id="molliepayments-oneoff"]')->length)->toBe($oneoff ? 1 : 0)
        ->and($xpath->query('//style | //*[@style]')->length)->toBe(0);
})->with([['both', true, true], ['one_time_only', false, true], ['recurring_only', true, false]]);

test('onboarding refuses a stale form after the saved payment mode changes', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $params = mollieConsentParams($ctx);
    mollieSetPaymentMode($ctx, 'recurring_only');
    $api = mollieClientApi($ctx, $params);
    expect(fn () => $api->onboarding_start($params))->toThrow(Payment_Exception::class, 'authorization changed')
        ->and($ctx->requests)->toBeEmpty()->and($ctx->customerRequests)->toBeEmpty();
});
