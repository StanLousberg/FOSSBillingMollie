<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\Credentials;

require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';

require_once dirname(__DIR__) . '/Support.php';

test('native credential fields are passwords and secrets with environment requirements', function (): void {
    $config = Payment_Adapter_MolliePayments::getConfig();
    expect($config['description'])->toBe('Mollie Payments for FOSSBilling');
    foreach (['api_key' => false, 'test_api_key' => true] as $field => $testMode) {
        expect($config['form'][$field][0])->toBe('password')
            ->and($config['form'][$field][1]['secret'])->toBeTrue()
            ->and($config['form'][$field][1]['required_when'])->toBe(['enabled' => true, 'test_mode' => $testMode])
            ->and($config['form'][$field][1])->not->toHaveKey('description')
            ->and($config['form'][$field][1]['label'])->toBe($testMode ? 'Test API Key (test_xxx)' : 'Live API Key (live_xxx)');
    }
});

test('enabled gateway validation accepts the active environment without checkout callbacks', function (bool $testMode): void {
    [$service] = mollieGatewayHost();
    $field = $testMode ? 'test_api_key' : 'api_key';
    $gateway = mollieGateway([$field => ($testMode ? 'test_' : 'live_') . bin2hex(random_bytes(20))]);

    expect($service->update($gateway, ['enabled' => true, 'test_mode' => $testMode]))->toBeTrue()
        ->and($gateway->isTestMode())->toBe($testMode)
        ->and($service->getPaymentAdapter($gateway)->getTestMode())->toBe($testMode);
})->with([true, false]);

test('enabled gateway requires the active credential', function (bool $testMode): void {
    [$service] = mollieGatewayHost();
    $gateway = mollieGateway();
    expect(fn () => $service->update($gateway, ['enabled' => true, 'test_mode' => $testMode]))
        ->toThrow(FOSSBilling\Core\Exception\BaseException::class, 'No saved');
    expect($gateway->isEnabled())->toBeFalse();
})->with([true, false]);

test('prefix validation rejects malformed or mismatched credentials without exposing them', function (mixed $key): void {
    try {
        Credentials::key(['test_api_key' => $key], 'test');
        test()->fail('Invalid key was accepted.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toStartWith('Invalid Test API key.');
        if (is_string($key) && $key !== 'test_') {
            expect($exception->getMessage())->not->toContain($key);
        }
    }
})->with(['wrong-prefix', 'live_dummy-value', 'test_', "test_value\n", ' test_value', "test_value\0", ['invalid']]);

test('prefix validation does not prescribe a full key format', function (): void {
    expect(Credentials::key(['api_key' => 'live_future-format.value-123'], 'live'))
        ->toBe('live_future-format.value-123');
});

test('native enabled gateway update rejects the wrong credential environment', function (bool $testMode): void {
    [$service] = mollieGatewayHost();
    $field = $testMode ? 'test_api_key' : 'api_key';
    $wrongKey = ($testMode ? 'live_' : 'test_') . bin2hex(random_bytes(20));
    $gateway = mollieGateway();
    expect(fn () => $service->update($gateway, [
        'enabled' => true, 'test_mode' => $testMode, 'config' => [$field => $wrongKey],
    ]))->toThrow(FOSSBilling\Core\Exception\BaseException::class, $testMode ? 'Invalid Test API key.' : 'Invalid Live API key.');
    expect($gateway->isEnabled())->toBeFalse()->and($gateway->getConfig())->not->toContain($wrongKey);
})->with([true, false]);

test('native storage preserves blank secrets and safely replaces either key', function (string $field): void {
    [$service, $di] = mollieGatewayHost();
    $keys = ['api_key' => 'live_' . bin2hex(random_bytes(20)), 'test_api_key' => 'test_' . bin2hex(random_bytes(20))];
    $gateway = mollieGateway();
    $service->update($gateway, ['config' => $keys]);
    $stored = mollieGatewayConfig($gateway);
    foreach ($keys as $name => $key) {
        $ciphertext = $stored[$name];
        if (is_array($ciphertext)) {
            expect(array_keys($ciphertext))->toBe(['_fossbilling_encrypted']);
            $ciphertext = $ciphertext['_fossbilling_encrypted'];
        }
        expect($ciphertext)->toStartWith(FOSSBilling\Core\Security\Crypt::CURRENT_FORMAT_PREFIX)
            ->and($ciphertext)->not->toBe($key)
            ->and($di['crypt']->decrypt($ciphertext))->toBe($key);
    }
    $service->update($gateway, ['config' => ['api_key' => '', 'test_api_key' => '  ']]);
    $adapter = $service->getPaymentAdapter($gateway);
    foreach ($keys as $name => $key) {
        expect($adapter->getParam($name))->toBe($key);
    }
    $replacement = ($field === 'api_key' ? 'live_' : 'test_') . bin2hex(random_bytes(20));
    $service->update($gateway, ['config' => [$field => $replacement]]);
    $keys[$field] = $replacement;
    $adapter = $service->getPaymentAdapter($gateway);
    foreach ($keys as $name => $key) {
        expect($adapter->getParam($name))->toBe($key);
    }

    $safe = $service->toApiArray($gateway, true, $di['loggedin_admin']);
    expect($safe['config']['api_key'])->toBeNull()->and($safe['config']['test_api_key'])->toBeNull()
        ->and($safe['config']['api_key_set'])->toBeTrue()->and($safe['config']['test_api_key_set'])->toBeTrue()
        ->and(json_encode($safe))->not->toContain(...array_values($keys));
    $adapter = $service->getPaymentAdapter($gateway);
    foreach ($keys as $name => $key) {
        expect($adapter->getParam($name))->toBe($key);
    }
})->with(['api_key', 'test_api_key']);
