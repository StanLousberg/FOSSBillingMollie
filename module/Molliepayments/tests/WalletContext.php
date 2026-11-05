<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Support\Amount;

require_once __DIR__ . '/PaymentContext.php';

function mollieCredit(object $ctx, string $amount): void
{
    $ctx->clientService->addFunds($ctx->client, $amount, 'Disposable account credit');
}

function mollieWallet(object $ctx): int
{
    $minor = 0;
    foreach ($ctx->db->fetchFirstColumn('SELECT amount FROM client_balance WHERE client_id = ?', [$ctx->client->getId()]) as $amount) {
        $minor += Amount::minor((string) $amount);
    }

    return $minor;
}
