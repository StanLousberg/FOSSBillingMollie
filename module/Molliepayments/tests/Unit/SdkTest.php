<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Mollie\Api\MollieApiClient;

test('official Mollie PHP SDK is installed and autoloadable', function (): void {
    MollieClientFactory::bootSdk();

    expect(class_exists(MollieApiClient::class))->toBeTrue();
});

test('API client factory rejects an empty API key', function (): void {
    expect(fn () => MollieClientFactory::create(''))
        ->toThrow(InvalidArgumentException::class, 'A Mollie API key is required.');
});
