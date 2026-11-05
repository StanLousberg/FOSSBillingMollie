<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class CustomerRepository
{
    public const TABLE = 'molliepayments_customer';

    public function __construct(public readonly Connection $connection)
    {
    }

    public function find(int $clientId, int $gatewayId, string $environment): ?array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM molliepayments_customer WHERE client_id = ? AND gateway_id = ? AND environment = ?',
            [$clientId, $gatewayId, $environment]
        ) ?: null;
    }

    /** The caller must not wrap provider operations in an outer transaction. */
    public function reserve(array $record): array
    {
        try {
            $this->connection->transactional(fn () => $this->connection->insert(self::TABLE, $record));
        } catch (UniqueConstraintViolationException) {
            // A competing worker owns the same durable creation identity.
        }

        return $this->find((int) $record['client_id'], (int) $record['gateway_id'], $record['environment'])
            ?? throw new \Payment_Exception('Customer reservation conflict. Retry later.');
    }

    public function acquire(string $id, string $lease): bool
    {
        return $this->connection->executeStatement(
            'UPDATE molliepayments_customer SET lease = ?, lease_until = ? WHERE id = ? AND (lease IS NULL OR lease_until < ?)',
            [$lease, time() + 180, $id, time()]
        ) === 1;
    }

    /** Fence late workers: a response cannot overwrite a newer worker or recovery. */
    public function update(string $id, string $lease, array $changes): void
    {
        $changed = $this->connection->update(self::TABLE, $changes + ['updated_at' => time()], ['id' => $id, 'lease' => $lease]);
        // MySQL reports zero changed rows for repeated verification in the same second.
        // Distinguish that harmless no-op from losing ownership of the fenced row.
        if ($changed === 0 && $this->connection->fetchOne('SELECT id FROM molliepayments_customer WHERE id = ? AND lease = ?', [$id, $lease]) !== $id) {
            throw new \Payment_Exception('Customer worker lost its lease. Retry later.');
        }
    }

    public function release(string $id, string $lease): void
    {
        $this->connection->executeStatement('UPDATE molliepayments_customer SET lease = NULL, lease_until = 0 WHERE id = ? AND lease = ?', [$id, $lease]);
    }
}
