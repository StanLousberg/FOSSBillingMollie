<?php

declare(strict_types=1);

require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';

test('MolliePayments adapter wordt geladen', function (): void {
    expect(class_exists(Payment_Adapter_MolliePayments::class))->toBeTrue();
});

test('MolliePayments accepteert nog geen betalingen', function (): void {
    $config = Payment_Adapter_MolliePayments::getConfig();
    expect($config['supports_one_time_payments'])->toBeFalse();
    expect($config['supports_subscriptions'])->toBeFalse();
});
