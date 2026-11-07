<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** Epochs remain absolute; only presentation uses the authoritative host timezone. */
final class Timestamps
{
    public static function present(?int $epoch): ?string
    {
        if ($epoch === null || $epoch === 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $epoch))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format(\DateTimeInterface::ATOM);
    }
}
