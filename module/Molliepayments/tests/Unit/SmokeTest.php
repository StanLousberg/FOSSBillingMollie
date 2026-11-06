<?php

declare(strict_types=1);

require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';

test('MolliePayments gateway class loads', function (): void {
    expect(class_exists(Payment_Adapter_MolliePayments::class))->toBeTrue();
});

test('one-time checkout is supported and recurring payments remain disabled', function (): void {
    $config = Payment_Adapter_MolliePayments::getConfig();

    expect($config['supports_one_time_payments'])->toBeTrue();
    expect($config['supports_subscriptions'])->toBeFalse();
});

test('extension icon can be inlined without a public module asset request', function (): void {
    $modulePath = dirname(__DIR__, 2);
    $loader = new FOSSBilling\Core\Twig\TwigLoader(
        FOSSBilling\Core\Twig\AppArea::from('admin'),
        PATH_THEMES . '/admin_default',
    );
    $source = $loader->getSourceContext('mod_Molliepayments_icon.svg')->getCode();
    $document = new DOMDocument();
    expect($document->loadXML($source, LIBXML_NONET))->toBeTrue();
    $image = $document->getElementsByTagName('image')->item(0);
    $uri = $image->getAttributeNS('http://www.w3.org/1999/xlink', 'href');

    expect($uri)->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr($uri, strlen('data:image/png;base64,')), true))
        ->toBe(file_get_contents(dirname($modulePath, 2) . '/gateway/assets/mollie-icon.png'));
});

test('manifest uses only v1 fields and the host discovers the unchanged package icon', function (): void {
    $modulePath = dirname(__DIR__, 2);
    $manifest = json_decode(file_get_contents($modulePath . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(array_diff(array_keys($manifest), ['manifest_version', 'type', 'id', 'name', 'version', 'description', 'author', 'license', 'homepage_url', 'requires']))->toBe([]);
    $module = new FOSSBilling\Core\Module('molliepayments');
    $module->setDi(Tests\Helpers\container());
    expect($module->getManifest()['icon_url'])->toBe('/modules/Molliepayments/icon.svg');
});

test('gateway directory link exposes the original Mollie logo', function (): void {
    $logo = Payment_Adapter_MolliePayments::getConfig()['logo'];
    $path = PATH_LIBRARY . '/Payment/Adapter/' . $logo['logo'];

    expect(is_file($path))->toBeTrue()
        ->and(hash_file('sha256', $path))
        ->toBe('f024f10101463ae607a9dceead43742726708882d89e03e748a52a7119ed2fd2')
        ->and(getimagesize($path)[0])->toBe(32)
        ->and(getimagesize($path)[1])->toBe(32);
});
