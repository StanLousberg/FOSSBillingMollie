<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class AttemptRepository
{
    public const TABLE = 'molliepayments_attempt';

    public function __construct(public readonly Connection $connection)
    {
    }

    public function find(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM molliepayments_attempt WHERE id = ?', [$id])
            ?: throw new \Payment_Exception('Unknown MolliePayments attempt.', [], 404);

        return $this->normalize($row);
    }

    public function active(int $invoiceId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM molliepayments_attempt WHERE active_invoice = ?', [$invoiceId]);

        return $row ? $this->normalize($row) : null;
    }

    /** Reservation is committed before making any network request. */
    public function reserve(array $record): array
    {
        try {
            // A savepoint keeps PostgreSQL's surrounding transaction usable on conflict.
            $this->connection->transactional(fn () => $this->connection->insert(self::TABLE, $record));
        } catch (UniqueConstraintViolationException) {
            return $this->active((int) $record['invoice_id'])
                ?? throw new \Payment_Exception('MolliePayments reservation conflict. Please retry.');
        }

        return $this->find($record['id']);
    }

    public function update(string $id, array $changes): void
    {
        $this->connection->update(self::TABLE, $changes + ['updated_at' => time()], ['id' => $id]);
    }

    public function acquire(string $id, string $lease): bool
    {
        return $this->connection->executeStatement(
            'UPDATE molliepayments_attempt SET lease = ?, lease_until = ? WHERE id = ? AND (lease IS NULL OR lease_until < ?)',
            [$lease, time() + 180, $id, time()]
        ) === 1;
    }

    public function release(string $id, string $lease): void
    {
        $this->connection->executeStatement('UPDATE molliepayments_attempt SET lease = NULL, lease_until = 0 WHERE id = ? AND lease = ?', [$id, $lease]);
    }

    /** Caller holds the invoice lock first, keeping a consistent lock order. */
    public function lock(string $id): array
    {
        $this->connection->executeStatement('UPDATE molliepayments_attempt SET updated_at = updated_at WHERE id = ?', [$id]);

        return $this->find($id);
    }

    private function normalize(array $row): array
    {
        // SQLite's NUMERIC affinity returns floats; MySQL/PostgreSQL return decimal strings.
        $row['amount'] = is_float($row['amount']) ? number_format($row['amount'], 2, '.', '') : (string) $row['amount'];

        return $row;
    }
}
