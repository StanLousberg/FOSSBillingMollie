<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** Merchant policy for new checkout requests, independent of native subscriptions. */
enum PaymentMode: string
{
    case Both = 'both';
    case OneTimeOnly = 'one_time_only';
    case RecurringOnly = 'recurring_only';

    public static function resolve(mixed $value): self
    {
        return $value === null ? self::Both : (is_string($value) ? self::tryFrom($value) : null)
            ?? throw new \Payment_Exception('Invalid MolliePayments payment mode.', [], 409);
    }

    public function allows(string $sequence): bool
    {
        return match ($sequence) {
            'first' => $this !== self::OneTimeOnly,
            'oneoff' => $this !== self::RecurringOnly,
            default => false,
        };
    }
}
