<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

final class CheckoutUrl
{
    /** Only authenticated API links are passed here; never request parameters. */
    public static function validate(?string $url): string
    {
        if ($url === null || $url === '') {
            throw new \Payment_Exception('Mollie no longer provides a checkout link for this payment. Its payment attempt is preserved. Wait for its final status or reconcile it before retrying.', [], 409);
        }
        $parts = parse_url($url);
        // The API documents www.mollie.com/checkout links. Legacy hosted
        // checkout also uses /payscreen; no arbitrary Mollie subdomain is trusted.
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'www.mollie.com'
            || !preg_match('~^/(?:checkout|payscreen)(?:/|$)~D', $parts['path'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/[\\\\\x00-\x20\x7f]/', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) {
            throw new \Payment_Exception('Mollie returned an unsafe checkout URL. Reconcile the saved attempt; do not create another payment.', [], 409);
        }

        return $url;
    }
}
