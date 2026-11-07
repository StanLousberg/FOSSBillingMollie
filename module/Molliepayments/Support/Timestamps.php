<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** Native DATETIME storage in UTC keeps elapsed provider deadlines unambiguous across DST. */
final class Timestamps
{
    public static function now(int $offset = 0): string
    {
        return (new \DateTimeImmutable('@' . (time() + $offset)))
            ->format('Y-m-d H:i:s');
    }

    public static function expired(string $createdAt, int $seconds): bool
    {
        return (new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')))->getTimestamp() + $seconds <= time();
    }

    public static function present(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }
}
