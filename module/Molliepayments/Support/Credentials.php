<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

final class Credentials
{
    public static function field(string $environment): string
    {
        return match ($environment) {
            'live' => 'api_key',
            'test' => 'test_api_key',
            default => throw new \InvalidArgumentException('Select either the test or live environment.'),
        };
    }

    public static function key(array $config, string $environment): string
    {
        $value = $config[self::field($environment)] ?? null;
        $label = $environment === 'live' ? 'Live' : 'Test';
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException("No saved {$label} API key. Save the credential in the payment gateway settings first.");
        }

        // Check the environment and reject whitespace/control characters without
        // prescribing a fixed key length or an alphabet that Mollie may change.
        if (!is_string($value) || !str_starts_with($value, $environment . '_')
            || strlen($value) <= strlen($environment . '_') || preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException("Invalid {$label} API key. It must start with {$environment}_ and contain no whitespace or control characters.");
        }

        return $value;
    }

    public static function validateConfiguration(array $config): void
    {
        foreach (['live', 'test'] as $environment) {
            $field = self::field($environment);
            if (isset($config[$field]) && $config[$field] !== '') {
                self::key($config, $environment);
            }
        }

        self::key($config, !empty($config['test_mode']) ? 'test' : 'live');
    }
}
