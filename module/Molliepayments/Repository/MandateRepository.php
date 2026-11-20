<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Repository;

use Box\Mod\Molliepayments\Support\Timestamps;
use Doctrine\DBAL\Connection;

/** Provider metadata and append-only administration evidence; never financial state. */
final class MandateRepository
{
    public const SNAPSHOT_MAX_AGE = 86400;
    public const TABLE = 'molliepayments_mandate';
    public const AUDIT = 'molliepayments_mandate_audit';

    public function __construct(public readonly Connection $connection)
    {
    }

    public function find(string $mapping, string $mandate): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM molliepayments_mandate WHERE mapping_id = ? AND mandate_id = ?', [$mapping, $mandate]) ?: null;
    }

    public function known(string $mapping): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM molliepayments_mandate WHERE mapping_id = ? ORDER BY mandate_id', [$mapping]);
    }

    /** The existing Customer lease serializes discovery, refresh and revocation for one mapping. */
    public function write(array $mapping, string $lease, \Closure $operation): mixed
    {
        return $this->connection->transactional(function () use ($mapping, $lease, $operation) {
            // Conditional write locks the mapping and fences expired or replaced workers on both DBs.
            $owned = $this->connection->executeStatement(
                'UPDATE molliepayments_customer SET lease_until = ? WHERE id = ? AND lease = ? AND lease_until >= ? AND status = ? AND customer_id = ? AND profile_id = ? AND credential_fingerprint = ?',
                [Timestamps::now(180), $mapping['id'], $lease, Timestamps::now(), 'ready', $mapping['customer_id'], $mapping['profile_id'], $mapping['credential_fingerprint']]
            );
            // MariaDB may report zero for a same-second renewal; verify ownership after the write lock.
            if ($owned === 0 && !$this->connection->fetchOne('SELECT id FROM molliepayments_customer WHERE id = ? AND lease = ? AND lease_until >= ? AND status = ? AND customer_id = ? AND profile_id = ? AND credential_fingerprint = ?',
                [$mapping['id'], $lease, Timestamps::now(), 'ready', $mapping['customer_id'], $mapping['profile_id'], $mapping['credential_fingerprint']])) {
                throw new \Payment_Exception('Mandate operation lost its Customer lease. Reload and retry.', [], 409);
            }

            return $operation();
        });
    }

    public function save(string $mapping, string $mandate, array $changes): array
    {
        $row = $this->find($mapping, $mandate);
        if ($row === null) {
            $this->connection->insert(self::TABLE, $changes + ['id' => bin2hex(random_bytes(16)), 'mapping_id' => $mapping, 'mandate_id' => $mandate,
                'status' => 'unknown', 'method' => null, 'summary' => null, 'provider_created_at' => null, 'signature_date' => null,
                'verified_at' => null, 'checked_at' => Timestamps::now(), 'error' => null, 'revocation' => null]);
        } else {
            $this->connection->update(self::TABLE, $changes, ['id' => $row['id']]);
        }

        return $this->find($mapping, $mandate);
    }

    public function audit(string $mapping, ?string $mandate, ?int $admin, string $action, string $outcome): void
    {
        $actor = $admin === null ? [] : ['admin_id' => $admin];
        $this->connection->insert($admin === null ? 'molliepayments_mandate_system_audit' : self::AUDIT, $actor + ['mapping_id' => $mapping, 'mandate_id' => $mandate,
            'action' => $action, 'outcome' => $outcome, 'created_at' => Timestamps::now()]);
    }

    public function overview(array $filters, int $page, \FOSSBilling\Core\Pagination\Service $pager): array
    {
        $where = ['c.customer_id IS NOT NULL', "c.status <> 'deleted'"];
        $params = [];
        $grouped = ($filters['view'] ?? '') === 'customers';
        $attentionCondition = "(m.status <> 'valid' OR m.error IS NOT NULL OR m.verified_at IS NULL OR m.verified_at <= ? OR m.revocation IN ('requested', 'rejected'))";
        $reason = match ($filters['attention'] ?? '') {
            'failed' => 'm.error IS NOT NULL',
            'stale' => 'm.verified_at <= ?',
            'revocation' => "m.revocation IN ('requested', 'rejected')",
            'unverified' => 'm.id IS NOT NULL AND m.verified_at IS NULL',
            default => null,
        };
        if ($reason !== null) {
            $where[] = $reason;
            if ($filters['attention'] === 'stale') {
                $params[] = Timestamps::now(-self::SNAPSHOT_MAX_AGE);
            }
        }
        if (($filters['view'] ?? '') === 'attention') {
            $where[] = $attentionCondition;
            $params[] = Timestamps::now(-self::SNAPSHOT_MAX_AGE);
        }
        if (!$grouped) {
            $where[] = 'm.id IS NOT NULL';
        }
        if (($filters['environment'] ?? '') === 'all') {
            unset($filters['environment']);
        }
        foreach (['environment' => 'c.environment', 'status' => 'm.status', 'method' => 'm.method', 'mapping' => 'c.id'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = $column . ' = ?';
                $params[] = $filters[$key];
            }
        }
        if (($filters['mandate'] ?? '') !== '') {
            $where[] = 'm.mandate_id LIKE ?';
            $params[] = '%' . $filters['mandate'] . '%';
        }
        if (($filters['customer'] ?? '') !== '') {
            $name = $this->connection->getDatabasePlatform()->getConcatExpression("COALESCE(n.first_name, '')", "' '", "COALESCE(n.last_name, '')");
            $where[] = '(CAST(c.client_id AS CHAR) = ? OR ' . $name . ' LIKE ? OR n.email LIKE ?)';
            array_push($params, $filters['customer'], '%' . $filters['customer'] . '%', '%' . $filters['customer'] . '%');
        }
        $from = ' FROM molliepayments_customer c LEFT JOIN molliepayments_mandate m ON m.mapping_id = c.id LEFT JOIN client n ON n.id = c.client_id WHERE ' . implode(' AND ', $where);
        $counts = $this->connection->fetchAssociative("SELECT COUNT(m.id) AS mandates, COUNT(CASE WHEN m.status = 'valid' THEN 1 END) AS valid, COUNT(CASE WHEN " . $attentionCondition . ' THEN m.id END) AS attention' . $from, [Timestamps::now(-self::SNAPSHOT_MAX_AGE), ...$params]);
        $direction = ($filters['sort'] ?? '') === 'name_desc' ? ' DESC' : ' ASC';
        $order = ' ORDER BY LOWER(n.first_name)' . $direction . ', LOWER(n.last_name)' . $direction . ', c.client_id';
        $select = 'SELECT c.id AS mapping_id, c.client_id, c.customer_id, c.gateway_id, c.environment, c.status AS mapping_status, n.first_name, n.last_name, m.mandate_id, m.status, m.method, m.summary, m.verified_at, m.checked_at, m.error, m.revocation';
        $query = $grouped ? 'SELECT c.client_id' . $from . ' GROUP BY c.client_id, n.first_name, n.last_name' . $order : $select . $from . $order . ', c.id, m.mandate_id';
        $result = $pager->getPaginatedResultSet($query, $params, new \FOSSBilling\Core\Pagination\Options($page, 25));
        if ($result['pages'] > 0 && $page > $result['pages']) {
            $result = $pager->getPaginatedResultSet($query, $params, new \FOSSBilling\Core\Pagination\Options($result['pages'], 25));
        }
        if ($grouped && $result['list']) {
            $ids = array_map('intval', array_column($result['list'], 'client_id'));
            $result['list'] = $this->connection->fetchAllAssociative($select . $from . ' AND c.client_id IN (' . implode(',', $ids) . ')' . $order . ', c.id, m.mandate_id', $params);
        }

        return $result + array_map('intval', $counts);
    }
}
