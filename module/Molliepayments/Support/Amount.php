<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** The host ledger uses two decimals. Reject currencies requiring another scale. */
final class Amount
{
    public static function currency(string $currency): string
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)
            || !\Symfony\Component\Intl\Currencies::exists($currency)
            || \Symfony\Component\Intl\Currencies::getFractionDigits($currency) !== 2) {
            throw new \Payment_Exception('MolliePayments requires a currency with two decimal places.');
        }

        return $currency;
    }

    /** Quantize once at the boundary of the host's float-valued accounting API. */
    public static function fromHost(float $value): string
    {
        if (!is_finite($value) || abs($value) > 999999999999.99) {
            throw new \Payment_Exception('Invalid invoice amount.');
        }

        return number_format($value, 2, '.', '');
    }

    public static function minor(string $value): int
    {
        if (!preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', $value, $match)) {
            throw new \Payment_Exception('Invalid payment amount.');
        }

        $minor = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return ($match[1] === '-' ? -1 : 1) * $minor;
    }

    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . intdiv(abs($minor), 100) . '.' . str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
