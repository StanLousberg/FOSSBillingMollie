<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use Mollie\Api\MollieApiClient;
use RuntimeException;

final class MollieClientFactory
{
    /**
     * Register MolliePayments' private Composer dependencies without modifying
     * FOSSBilling's own Composer installation.
     */
    public static function bootSdk(): void
    {
        $autoloader = dirname(__DIR__) . '/vendor/autoload.php';

        if (!is_file($autoloader)) {
            throw new RuntimeException(
                'Mollie SDK is not installed. Run composer install from the MolliePayments repository.'
            );
        }

        require_once $autoloader;
    }

    public static function create(string $apiKey): MollieApiClient
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('A Mollie API key is required.');
        }

        self::bootSdk();

        $client = new MollieApiClient();
        $client->setApiKey($apiKey);

        return $client;
    }
}
