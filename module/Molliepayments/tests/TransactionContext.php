<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Transaction;

require_once __DIR__ . '/OnboardingContext.php';

/** Reproduce the native permissive creation contract, preserving stored GET hints without top-level association. */
function mollieOrphan(object $ctx, bool $invoiceHint = true): Transaction
{
    $get = ['attempt_id' => mollieAttempt($ctx)['id'], 'delivery' => bin2hex(random_bytes(16))];
    if ($invoiceHint) {
        $get['invoice_id'] = $ctx->invoice->getId();
    }
    $id = $ctx->transactionService->create(['gateway_id' => $ctx->gateway->getId(), 'skip_validation' => true,
        'get' => $get, 'post' => ['id' => $ctx->paymentId ?? 'tr_disposable123']]);

    return $ctx->em->find(Transaction::class, $id);
}
