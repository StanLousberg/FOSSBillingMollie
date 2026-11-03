<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\Repository\PayGatewayRepository;
use Box\Mod\Molliepayments\Api\Admin as MollieAdmin;
use Box\Mod\Molliepayments\Service as MollieService;
use Box\Mod\Molliepayments\Support\ConnectionChecker;
use FOSSBilling\Core\Exception\InformationException;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaginatedPaymentsRequest;
use Mollie\Api\MollieApiClient;

use function Tests\Helpers\admin;

require_once dirname(__DIR__) . '/Support.php';
require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';
Box\Mod\Molliepayments\Support\MollieClientFactory::bootSdk();

function mollieAdminContext(?ConnectionChecker $checker = null): array
{
    [$host, $di] = mollieGatewayHost();
    $repository = Mockery::mock(PayGatewayRepository::class);
    $di['em']->shouldReceive('getRepository')->with(PayGateway::class)->andReturn($repository);
    $staff = Mockery::mock(Box\Mod\Staff\Service::class);
    $di['mod_service'] = $di->protect(static fn (string $module, ?string $subservice = null) => strtolower($module) === 'staff' ? $staff : $host);
    $service = $checker === null ? new MollieService() : new MollieService($checker);
    $service->setDi($di);
    $api = new MollieAdmin();
    $api->setDi($di);
    $api->setService($service);
    $identity = admin(['id' => 9]);
    $api->setIdentity($identity);

    return [$api, $service, $repository, $staff, $identity, $host, $di];
}

test('single connection action uses only our adapter and its saved mode while disabled', function (string $environment): void {
    $client = MollieApiClient::fake([GetPaginatedPaymentsRequest::class => MockResponse::ok([
        'count' => 0, '_embedded' => ['payments' => []],
        '_links' => ['self' => ['href' => 'https://api.mollie.com/v2/payments?limit=1']],
    ])]);
    $keys = ['api_key' => mollieDummyKey('live'), 'test_api_key' => mollieDummyKey('test')];
    $checker = new ConnectionChecker(function (string $key) use ($keys, $client, $environment): MollieApiClient {
        expect($key)->toBe($keys[$environment === 'test' ? 'test_api_key' : 'api_key']);

        return $client->setApiKey($key);
    });
    [$api, , $repository, $staff, $identity, $host, $di] = mollieAdminContext($checker);
    $staff->shouldReceive('checkPermissionsAndThrowException')->once()->with('invoice', 'manage_gateways', null, $identity);
    $gateway = mollieGateway([], ['testMode' => $environment === 'test']);
    $host->update($gateway, ['config' => $keys]);
    $stored = $gateway->getConfig();
    $repository->shouldReceive('findBy')->once()->with(['gateway' => 'MolliePayments'])->andReturn([$gateway]);
    $repository->shouldNotReceive('find');

    $result = $api->connection_test(['gateway_id' => '999', 'environment' => $environment === 'test' ? 'live' : 'test', 'api_key' => 'untrusted', 'endpoint' => 'https://untrusted.invalid']);
    expect($result['success'])->toBeTrue()
        ->and($result['environment'])->toBe($environment)
        ->and($gateway->isEnabled())->toBeFalse()->and($gateway->isTestMode())->toBe($environment === 'test')
        ->and($gateway->getConfig())->toBe($stored)
        ->and(json_encode([$result, $di['logger']->calls]))->not->toContain(...array_values($keys));
    $client->assertSentCount(1);
})->with(['test', 'live']);

test('non-admin identities cannot test the connection', function (): void {
    [$api, , $repository, $staff] = mollieAdminContext();
    $api->setIdentity(new FOSSBilling\Core\Identity\Guest());
    $repository->shouldNotReceive('find');
    $repository->shouldNotReceive('findBy');
    $staff->shouldNotReceive('checkPermissionsAndThrowException');
    expect(fn () => $api->connection_test())
        ->toThrow(InformationException::class, 'Administrator authentication');
});

test('gateway permission is checked before reading saved credentials', function (): void {
    [$api, , $repository, $staff, $identity] = mollieAdminContext();
    $staff->shouldReceive('checkPermissionsAndThrowException')->once()
        ->with('invoice', 'manage_gateways', null, $identity)
        ->andThrow(new InformationException('Permission denied', [], 403));
    $repository->shouldNotReceive('find');
    $repository->shouldNotReceive('findBy');
    expect(fn () => $api->connection_test())
        ->toThrow(InformationException::class, 'Permission denied');
});

test('connection test rejects absent, foreign or ambiguous adapter configurations', function (string $scenario): void {
    [, $service, $repository] = mollieAdminContext();
    $gateways = match ($scenario) {
        'missing' => [],
        'foreign' => [mollieGateway([], ['gateway' => 'Stripe'])],
        'ambiguous' => [mollieGateway(), mollieGateway([], ['id' => 42])],
    };
    $repository->shouldReceive('findBy')->once()->with(['gateway' => 'MolliePayments'])->andReturn($gateways);
    expect(fn () => $service->testConnection())->toThrow(InformationException::class);
})->with(['missing', 'foreign', 'ambiguous']);

test('native gateway admin API masks both saved credentials', function (): void {
    [, , $repository, $staff, $identity, $host, $di] = mollieAdminContext();
    $staff->shouldReceive('checkPermissionsAndThrowException')->once()->with('invoice', 'manage_gateways', null, $identity);
    $keys = ['api_key' => mollieDummyKey('live'), 'test_api_key' => mollieDummyKey('test')];
    $gateway = mollieGateway();
    $host->update($gateway, ['config' => $keys]);
    $repository->shouldReceive('find')->once()->with(41)->andReturn($gateway);
    $api = new Box\Mod\Invoice\Api\Admin();
    $api->setDi($di);
    $api->setIdentity($identity);
    $result = $api->gateway_get(['id' => 41]);

    expect($result['config']['api_key'])->toBeNull()->and($result['config']['test_api_key'])->toBeNull()
        ->and($result['config']['api_key_set'])->toBeTrue()->and($result['config']['test_api_key_set'])->toBeTrue()
        ->and(json_encode($result))->not->toContain(...array_values($keys));
});

test('configuration errors are sanitized before the host API logger can see them', function (): void {
    [, $service, $repository, , , , $di] = mollieAdminContext();
    $gateway = mollieGateway();
    $repository->shouldReceive('findBy')->with(['gateway' => 'MolliePayments'])->andReturn([$gateway]);
    $key = mollieDummyKey('live');
    $host = Mockery::mock(Box\Mod\Invoice\ServicePayGateway::class);
    $host->shouldReceive('getPaymentAdapter')->with($gateway)->andThrow(new RuntimeException('Sensitive ' . $key));
    unset($di['mod_service']);
    $di['mod_service'] = $di->protect(static fn () => $host);
    $result = $service->testConnection();
    expect($result['success'])->toBeFalse()->and(json_encode([$result, $di['logger']->calls]))->not->toContain($key, 'Sensitive');
});

test('native settings render one connection button and no adapter or environment controls', function (): void {
    $modulePath = dirname(__DIR__, 2);
    $loader = new Twig\Loader\ChainLoader([
        new Twig\Loader\FilesystemLoader($modulePath . '/templates/admin'),
        new Twig\Loader\ArrayLoader(['layout_default.html.twig' => '{% block content %}{% endblock %}{% block js %}{% endblock %}']),
    ]);
    $twig = new Twig\Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
    $di = Tests\Helpers\container();
    $twig->addExtension(new Twig\Extension\AttributeExtension(FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class));
    $twig->addExtension(new Twig\Extension\AttributeExtension(FOSSBilling\Core\Twig\Extension\ApiExtension::class));
    $twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([
        FOSSBilling\Core\Twig\Extension\FOSSBillingExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\FOSSBillingExtension($di),
        FOSSBilling\Core\Twig\Extension\ApiExtension::class => static fn () => new FOSSBilling\Core\Twig\Extension\ApiExtension($di),
    ]));
    $twig->addGlobal('app_area', 'admin');
    $html = $twig->render('mod_molliepayments_settings.html.twig', [
        'request' => ['ajax' => false],
        'CSRFToken' => 'disposable-csrf-token',
    ]);
    expect($html)->toContain('Test connection', 'name="CSRFToken"', 'disposable-csrf-token', '/api/admin/molliepayments/connection_test', 'Testing connection')
        ->not->toContain('name="api_key"', 'name="test_api_key"', 'name="gateway_id"', 'name="environment"', '<select', '<table');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    expect($document->getElementsByTagName('button')->length)->toBe(1);
});

test('FOSSBilling discovers a native settings page without a standalone module controller', function (): void {
    $di = Tests\Helpers\container();
    $module = new FOSSBilling\Core\Module('molliepayments');
    $module->setDi($di);
    expect($module->hasSettingsPage())->toBeTrue()
        ->and($module->hasAdminController())->toBeFalse()
        ->and((new MollieService())->getModulePermissions())->toBe(['manage_settings' => []]);
});

test('native admin rendering discovers symlinked module settings while preserving theme priority', function (): void {
    $di = Tests\Helpers\container();
    $originalLoader = new Twig\Loader\ArrayLoader(['theme-owned.html.twig' => 'Theme content']);
    $twig = new Twig\Environment($originalLoader);
    $factory = Mockery::mock(FOSSBilling\Core\Twig\TwigFactory::class);
    $factory->shouldReceive('createAdminEnvironment')->once()->andReturn($twig);
    $di['twig_factory'] = static fn () => $factory;
    // Also exercise registration after Pimple has frozen the original factory.
    expect($di['twig_factory'])->toBe($factory);
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $di['em']->shouldReceive('getRepository')->with(Box\Mod\Extension\Entity\Extension::class)
        ->andReturn(Mockery::mock(Box\Mod\Extension\Repository\ExtensionRepository::class));
    $di['em']->shouldReceive('getRepository')->with(Box\Mod\Extension\Entity\ExtensionMeta::class)
        ->andReturn(Mockery::mock(Box\Mod\Extension\Repository\ExtensionMetaRepository::class));
    $extensionService = new Box\Mod\Extension\Service();
    $extensionService->setDi($di);
    // The stock settings controller discovers these permissions before render().
    expect($extensionService->getSpecificModulePermissions('molliepayments'))->toHaveKey('manage_settings');
    $registered = $di['twig_factory'];
    $extensionService->getSpecificModulePermissions('molliepayments');
    expect($di['twig_factory'])->toBe($registered);
    $environment = $registered->createAdminEnvironment(new DebugBar\StandardDebugBar());
    expect($environment->getLoader()->exists('mod_molliepayments_settings.html.twig'))->toBeTrue()
        ->and($environment->render('theme-owned.html.twig'))->toBe('Theme content');
    $originalLoader->setTemplate('mod_molliepayments_settings.html.twig', 'Theme override');
    expect($environment->render('mod_molliepayments_settings.html.twig'))->toBe('Theme override');
});
