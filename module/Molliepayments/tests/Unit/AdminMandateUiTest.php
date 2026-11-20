<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/AdminMandateContext.php';

function mollieMandateTwig(object $ctx): Twig\Environment
{
    $loader = new Twig\Loader\ChainLoader([
        new Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates/admin'),
        new Twig\Loader\ArrayLoader(['layout_default.html.twig' => '{% block top_content %}{% endblock %}{% block content %}{% endblock %}{% block js %}{% endblock %}']),
    ]);
    $twig = new Twig\Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    foreach ([FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class, FOSSBilling\Core\Twig\Extension\ApiExtension::class] as $class) {
        $twig->addExtension(new Twig\Extension\AttributeExtension($class));
    }
    $twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([
        FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\FOSSBillingExtension($ctx->di),
        FOSSBilling\Core\Twig\Extension\ApiExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\ApiExtension($ctx->di),
    ]));
    $ctx->di['auth']->shouldReceive('isAdminLoggedIn')->andReturn(true);
    $ctx->di['loggedin_admin'] = Tests\Helpers\admin(['id' => 7]);
    $twig->addGlobal('app_area', 'admin');

    return $twig;
}

test('native Twig renders filtered mandate overview details audit and confirmation with escaped content', function (bool $permitted): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->client->setFirstName('<script>unsafe</script>');
    $ctx->em->flush();
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $other = mollieCustomerRow($ctx);
    $other['id'] = str_repeat('b', 32);
    ++$other['client_id'];
    $other['customer_id'] = 'cst_another';
    $other['idempotency_key'] = str_repeat('e', 64);
    $ctx->db->insert('molliepayments_customer', $other);
    (new Box\Mod\Molliepayments\Repository\MandateRepository($ctx->db))->save($other['id'], 'mdt_another', []);
    $api = mollieMandateAdminApi($ctx, 'mandate_list');
    $ctx->staff->shouldReceive('hasPermission')->andReturn($permitted);
    $proxy = new class($api) {
        public function __construct(private readonly Box\Mod\Molliepayments\Api\Admin $api)
        {
        }

        public function molliepayments_mandate_list(array $data): array
        {
            return $this->api->mandate_list($data);
        }

        public function molliepayments_mandate_get(array $data): array
        {
            return $this->api->mandate_get($data);
        }
    };
    $twig = mollieMandateTwig($ctx);
    $vars = ['admin' => $proxy, 'request' => [], 'CSRFToken' => 'disposable-admin-nonce'];
    $overview = $twig->render('mod_molliepayments_mandates.html.twig', $vars);
    $dom = new DOMDocument();
    $dom->loadHTML($overview, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $group = $xpath->query('//div[@id="customer-' . $ctx->client->getId() . '"]')->item(0);
    expect($xpath->query('//section[@aria-label="Overview totals"]//span')->item(1)->textContent)->toBe('2')
        ->and($xpath->query('.//a[contains(@class, "btn-icon")]', $group)->item(0)->getAttribute('href'))->toEndWith('/' . $ctx->mappingId . '/mdt_disposable');
    expect($group->textContent)->toContain('mdt_disposable')->not->toContain('mdt_another', 'cst_disposable');
    expect($xpath->query('//select[@name="method"]/option')->length)->toBe(4)
        ->and($overview)->toContain('card-subtitle', 'Reset', 'Apply Filters', 'placeholder="mdt_', 'value="directdebit"', 'value="creditcard"', 'value="paypal"')
        ->not->toContain('Gateway default', 'Customer order', 'All mandates');
    $detail = $twig->render('mod_molliepayments_mandate.html.twig', $vars + ['mapping_id' => $ctx->mappingId, 'mandate_id' => 'mdt_disposable']);
    expect($overview)->toContain('mdt_disposable', 'cst_disposable', 'name="environment"', 'name="customer"', 'Page', 'valid', '&lt;script&gt;unsafe&lt;/script&gt;')
        ->not->toContain('<script>unsafe</script>');
    expect($detail)->toContain('Orders covered by verified consent', 'No currently applicable orders', 'Bank account **** 0000', 'No verified originating payment', 'Administrators cannot grant consent', 'Administrative audit history')
        ->not->toContain('NL00TEST', 'Private Instrument', '<script>unsafe</script>');
    if ($permitted) {
        expect($detail)->toContain('name="CSRFToken"', 'name="confirmation"', 'provider subscriptions', '/api/admin/molliepayments/mandate_revoke', 'aria-live="polite"');
    } else {
        expect($detail)->not->toContain('name="confirmation"', 'id="revoke-mandate"', '/api/admin/molliepayments/mandate_revoke');
    }
})->with([true, false]);

test('mandate templates show a useful empty state for customers with no mappings', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $api = mollieMandateAdminApi($ctx, 'mandate_list');
    $ctx->staff->shouldReceive('hasPermission')->andReturn(true);
    $proxy = new class($api) {
        public function __construct(private readonly Box\Mod\Molliepayments\Api\Admin $api)
        {
        }

        public function molliepayments_mandate_list(array $data): array
        {
            return $this->api->mandate_list($data);
        }
    };
    $html = mollieMandateTwig($ctx)->render('mod_molliepayments_mandates.html.twig', ['admin' => $proxy, 'request' => ['customer' => 'unmatched'], 'CSRFToken' => 'disposable-admin-nonce']);
    expect($html)->toContain('No matching Customer mappings', 'Listing never creates');
});

test('additive installation preserves existing payment and customer rows and retains administration on update', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $customers = $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_customer');
    $mandates = $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate');
    $audit = $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate_audit');
    $history = mollieMandateHistory($ctx);
    $ctx->services['molliepayments']->update();
    expect($ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_customer'))->toBe($customers)
        ->and($ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate'))->toBe($mandates)
        ->and($ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_mandate_audit'))->toBe($audit)
        ->and(mollieMandateHistory($ctx))->toBe($history);
});

test('a theme settings override retains precedence while new mandate templates remain available', function (): void {
    $ctx = molliePaymentContext();
    $twig = new Twig\Environment(new Twig\Loader\ArrayLoader(['mod_molliepayments_settings.html.twig' => 'Theme settings']));
    $factory = Mockery::mock(FOSSBilling\Core\Twig\TwigFactory::class);
    $factory->shouldReceive('createAdminEnvironment')->once()->andReturn($twig);
    $ctx->di['twig_factory'] = $factory;
    Box\Mod\Molliepayments\Support\AdminTwigFactory::register($ctx->di);
    $environment = $ctx->di['twig_factory']->createAdminEnvironment(new DebugBar\StandardDebugBar());
    expect($environment->render('mod_molliepayments_settings.html.twig'))->toBe('Theme settings')
        ->and($environment->getLoader()->exists('mod_molliepayments_mandates.html.twig'))->toBeTrue()
        ->and($environment->getLoader()->exists('mod_molliepayments_mandate_status.html.twig'))->toBeTrue();
});

test('upgrading an older module schema adds mandate tables without changing legacy columns or records', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    // Only this disposable in-memory database is reduced to the previous module schema.
    $schema = $ctx->db->createSchemaManager();
    $schema->dropTable('molliepayments_mandate_audit');
    $schema->dropTable('molliepayments_mandate');
    $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt ADD COLUMN retained_legacy_note VARCHAR(32) DEFAULT NULL');
    $ctx->db->update('molliepayments_attempt', ['retained_legacy_note' => 'Retain historical evidence'], ['id' => mollieAttempt($ctx)['id']]);
    $history = mollieMandateHistory($ctx);
    $mapping = mollieCustomerRow($ctx);
    expect($ctx->services['molliepayments']->update())->toBeTrue()
        ->and($schema->tablesExist(['molliepayments_mandate', 'molliepayments_mandate_audit']))->toBeTrue()
        ->and(mollieMandateHistory($ctx))->toBe($history)->and(mollieCustomerRow($ctx))->toBe($mapping);
});

test('the native admin router matches the module activation destination to settings', function (): void {
    $app = new class extends FOSSBilling\Core\Http\App {
        public function activationHandler(): ?string
        {
            foreach ($this->sharedRouteDefinitions as $route) {
                if ((new FOSSBilling\Core\Http\RouteMatcher())->match($route->httpMethod, $route->path, $route->conditions, '/molliepayments', 'GET')->matched) {
                    return $route->methodName;
                }
            }

            return null;
        }
    };
    (new Box\Mod\Molliepayments\Controller\Admin())->register($app);
    expect($app->activationHandler())->toBe('get_settings');
});

test('activation uses native settings authorization and the admin redirect', function (bool $allowed): void {
    $di = Tests\Helpers\container();
    $di['is_admin_logged'] = true;
    $extensions = Mockery::mock(Box\Mod\Extension\Service::class);
    $check = $extensions->shouldReceive('hasManagePermission')->once()->with('molliepayments');
    $di['mod_service'] = $di->protect(static fn () => $extensions);
    $controller = new Box\Mod\Molliepayments\Controller\Admin();
    $controller->setDi($di);
    $app = Mockery::mock(FOSSBilling\Core\Http\AppAdmin::class);
    if (!$allowed) {
        $check->andThrow(new FOSSBilling\Core\Exception\InformationException('Settings denied', [], 403));
        expect(fn () => $controller->get_settings($app))->toThrow(FOSSBilling\Core\Exception\InformationException::class);

        return;
    }
    $app->shouldReceive('redirect')->once()->with('/extension/settings/molliepayments')->andReturn(new Symfony\Component\HttpFoundation\RedirectResponse('/staff-area/extension/settings/molliepayments'));
    expect($controller->get_settings($app)->getTargetUrl())->toBe('/staff-area/extension/settings/molliepayments');
})->with([true, false]);
