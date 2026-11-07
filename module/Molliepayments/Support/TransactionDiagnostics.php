<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Doctrine\DBAL\Connection;

/** Local clues only, never authorization to relink financial history. No writes or provider calls. */
final class TransactionDiagnostics
{
    public static function orphans(Connection $db, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Diagnostic limit must be between 1 and 100.');
        }
        $table = $db->quoteSingleIdentifier('transaction');
        $rows = $db->fetchAllAssociative("SELECT t.id, t.status, t.gateway_id, t.ipn FROM {$table} t
            INNER JOIN pay_gateway g ON g.id = t.gateway_id
            WHERE t.invoice_id IS NULL AND g.gateway = ? ORDER BY t.id DESC LIMIT {$limit}", ['MolliePayments']);
        $attempts = new AttemptRepository($db);

        return array_map(static function (array $row) use ($attempts): array {
            $ipn = is_string($row['ipn']) ? json_decode($row['ipn'], true) : $row['ipn'];
            $id = $ipn['get']['attempt_id'] ?? null;
            $payment = $ipn['post']['id'] ?? null;
            $candidate = null;
            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) && is_string($payment) && preg_match('/^tr_[A-Za-z0-9]{1,60}$/D', $payment)) {
                try {
                    $attempt = $attempts->find($id);
                    if ((int) $attempt['gateway_id'] === (int) $row['gateway_id'] && $attempt['payment_id'] === $payment) {
                        $candidate = (int) $attempt['invoice_id'];
                    }
                } catch (\Payment_Exception) {
                    // Unknown attempt: expose no untrusted identifiers or IPN contents.
                }
            }

            return ['transaction_id' => (int) $row['id'], 'status' => $row['status'],
                'local_candidate_invoice_id' => $candidate, 'provider_verified' => false,
                'action' => $row['status'] === Transaction::STATUS_PROCESSED ? 'preserve_processed_history_operator_review' : 'native_claimed_reprocessing_requires_provider_verification'];
        }, $rows);
    }
}
