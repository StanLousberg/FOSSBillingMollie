<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Molliepayments\Repository\MandateRepository;
use Box\Mod\Molliepayments\Support\Timestamps;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Exceptions\ServerException;
use Mollie\Api\Exceptions\TooManyRequestsException;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\MandateCollection;

/** Administration never supplies consent or changes onboarding/payment evidence. */
final class AdminMandateService
{
    private readonly MandateSnapshotService $snapshots;
    private readonly MandateRepository $mandates;

    public function __construct(private readonly \Pimple\Container $di, private readonly ?\Closure $clientFactory = null)
    {
        Support\Compatibility::assertHost();
        $this->snapshots = new MandateSnapshotService($di, $clientFactory);
        $this->mandates = new MandateRepository($di['em']->getConnection());
    }

    public function overview(array $filters, int $page): array
    {
        $filters += ['view' => 'customers'];
        if (($filters['environment'] ?? '') === '') {
            $gateways = $this->di['em']->getRepository(\Box\Mod\Invoice\Entity\PayGateway::class)->findBy(['gateway' => 'MolliePayments', 'enabled' => true], null, 2);
            $filters['environment'] = count($gateways) === 1 ? ($gateways[0]->isTestMode() ? 'test' : 'live') : 'unconfigured';
        }
        $result = $this->mandates->overview($filters, $page, $this->di['pager']);
        foreach ($result['list'] as &$row) {
            $row['aged'] = $row['verified_at'] !== null && Timestamps::expired($row['verified_at'], MandateRepository::SNAPSHOT_MAX_AGE);
            $row['verified_at'] = Timestamps::present($row['verified_at']);
            $row['checked_at'] = Timestamps::present($row['checked_at']);
        }

        unset($row);
        $result['environment'] = $filters['environment'];
        $result['groups'] = [];
        if (($filters['view'] ?? '') === 'customers') {
            foreach ($result['list'] as $row) {
                $result['groups'][$row['client_id']][] = $row;
            }
        }

        return $result;
    }

    public function detail(string $mappingId, string $mandateId): array
    {
        $mapping = $this->mapping($mappingId);
        $row = $this->mandates->find($mappingId, $mandateId)
            ?? throw new \Payment_Exception('Mandate not found in this Customer mapping.', [], 404);
        $native = $this->di['em']->find(Client::class, (int) $mapping['client_id']);
        $audit = $this->mandates->connection->fetchAllAssociative('SELECT admin_id, action, outcome, created_at FROM (SELECT id, admin_id, mapping_id, mandate_id, action, outcome, created_at FROM molliepayments_mandate_audit UNION ALL SELECT id, NULL, mapping_id, mandate_id, action, outcome, created_at FROM molliepayments_mandate_system_audit) journal WHERE mapping_id = ? AND (mandate_id = ? OR mandate_id IS NULL) ORDER BY created_at DESC, id DESC, admin_id DESC LIMIT 100', [$mappingId, $mandateId]);
        foreach ($audit as &$entry) {
            $entry['created_at'] = Timestamps::present($entry['created_at']);
        }
        // A stored association comes from authenticated payment verification, never instrument matching.
        $attempts = $this->mandates->connection->fetchAllAssociative("SELECT * FROM molliepayments_attempt WHERE client_id = ? AND gateway_id = ? AND environment = ? AND mandate_id = ? AND consent_version IS NOT NULL AND settled_transaction_id IS NOT NULL AND status = 'paid' ORDER BY created_at DESC, id DESC LIMIT 26", [$mapping['client_id'], $mapping['gateway_id'], $mapping['environment'], $mandateId]);
        $references = [];
        $unverified = false;
        $more = count($attempts) > 25;
        $payments = new PaymentService($this->di, $this->clientFactory);
        foreach (array_slice($attempts, 0, 25) as $attempt) {
            try {
                $attempt = Repository\AttemptRepository::normalize($attempt);
                $scope = $payments->assertAuthorization($attempt);
                $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
                if (($payload['customerId'] ?? null) !== $mapping['customer_id'] || ($payload['metadata']['customer_mapping'] ?? null) !== $mapping['id']
                    || ($payload['metadata']['profile_id'] ?? null) !== $mapping['profile_id']) {
                    throw new \UnexpectedValueException();
                }
                $coverage = Support\RecurringEligibility::applicability($this->di['em'], $scope['recurring_orders'], (int) $attempt['client_id'], $attempt['currency']);
                $references[] = ['applicable_order_ids' => array_column($coverage['applicable_orders'], 'order_id'), 'order_ids' => array_column($scope['recurring_orders'], 'order_id'), 'id' => $attempt['id'], 'invoice_id' => (int) $attempt['invoice_id'], 'payment_id' => $attempt['payment_id'],
                    'consent_version' => $attempt['consent_version'], 'consent_accepted_at' => Timestamps::present($attempt['consent_accepted_at']),
                    'mandate_verified_at' => Timestamps::present($attempt['mandate_verified_at'])];
            } catch (\Throwable) {
                $unverified = true;
            }
        }
        $row['aged'] = $row['verified_at'] !== null && Timestamps::expired($row['verified_at'], MandateRepository::SNAPSHOT_MAX_AGE);
        foreach (['verified_at', 'checked_at', 'provider_created_at'] as $date) {
            $row[$date] = Timestamps::present($row[$date]);
        }

        return $row + ['client_id' => (int) $mapping['client_id'], 'customer_name' => $native?->getFullName() ?? 'Deleted customer',
            'customer_id' => $mapping['customer_id'], 'environment' => $mapping['environment'], 'gateway_id' => (int) $mapping['gateway_id'],
            'profile_id' => $mapping['profile_id'], 'mapping_status' => $mapping['status'], 'audit' => $audit, 'references' => $references, 'more_references' => $more, 'unverified_references' => $unverified];
    }

    public function synchronize(string $mappingId, int $adminId): array
    {
        return $this->operate($mappingId, null, $adminId, 'synchronize', function (array $mapping, MollieApiClient $client, string $lease) use ($adminId): array {
            $seen = [];
            $from = null;
            $cursors = [];
            for ($page = 0; $page < 20; ++$page) {
                $this->snapshots->fence($mapping, $lease);
                $collection = $client->send(new GetPaginatedMandateRequest($mapping['customer_id'], from: $from, limit: 250));
                if (!$collection instanceof MandateCollection) {
                    throw new \UnexpectedValueException();
                }
                foreach ($collection as $mandate) {
                    $this->snapshots->snapshot($mapping, $client, $lease, $mandate->id, $adminId, $mandate);
                    $seen[$mandate->id] = true;
                }
                if (!$collection->hasNext()) {
                    break;
                }
                // Rebuild a typed SDK request, never follow an arbitrary provider link with credentials.
                $next = $collection->_links->next->href;
                $parts = parse_url($next);
                parse_str($parts['query'] ?? '', $query);
                $from = $query['from'] ?? null;
                if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.mollie.com'
                    || ($parts['path'] ?? '') !== '/v2/customers/' . $mapping['customer_id'] . '/mandates'
                    || !is_string($from) || !preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $from) || isset($cursors[$from]) || $page === 19) {
                    throw new \UnexpectedValueException();
                }
                $cursors[$from] = true;
            }
            // List omissions alone never establish revocation. Verify each known omitted mandate directly.
            $known = array_column($this->mandates->known($mapping['id']), 'mandate_id');
            $attempts = $this->mandates->connection->fetchAllAssociative('SELECT mandate_id, request FROM molliepayments_attempt WHERE client_id = ? AND gateway_id = ? AND environment = ? AND mandate_id IS NOT NULL', [$mapping['client_id'], $mapping['gateway_id'], $mapping['environment']]);
            foreach ($attempts as $attempt) {
                $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
                if (($payload['customerId'] ?? null) === $mapping['customer_id']
                    && ($payload['metadata']['customer_mapping'] ?? null) === $mapping['id']
                    && ($payload['metadata']['profile_id'] ?? null) === $mapping['profile_id']
                    && preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $attempt['mandate_id'])) {
                    $known[] = $attempt['mandate_id'];
                }
            }
            foreach (array_unique($known) as $id) {
                if (!isset($seen[$id])) {
                    $this->snapshots->snapshot($mapping, $client, $lease, $id, $adminId);
                }
            }
            $this->mandates->write($mapping, $lease, fn () => $this->mandates->audit($mapping['id'], null, $adminId, 'synchronize', 'verified'));

            return ['success' => true, 'message' => 'Customer mandates synchronized. Discovery does not grant customer authorization.'];
        });
    }

    public function refresh(string $mappingId, string $mandateId, int $adminId): array
    {
        if ($this->mandates->find($mappingId, $mandateId) === null) {
            throw new \Payment_Exception('Mandate not found in this Customer mapping.', [], 404);
        }

        return $this->operate($mappingId, $mandateId, $adminId, 'refresh', function (array $mapping, MollieApiClient $client, string $lease) use ($mandateId, $adminId): array {
            $this->snapshots->snapshot($mapping, $client, $lease, $mandateId, $adminId);

            return ['success' => true, 'message' => 'Mandate provider state verified.'];
        });
    }

    public function revoke(string $mappingId, string $mandateId, int $adminId): array
    {
        if ($this->mandates->find($mappingId, $mandateId) === null) {
            throw new \Payment_Exception('Mandate not found in this Customer mapping.', [], 404);
        }

        return $this->operate($mappingId, $mandateId, $adminId, 'revoke', function (array $mapping, MollieApiClient $client, string $lease) use ($mandateId, $adminId): array {
            $row = $this->snapshots->snapshot($mapping, $client, $lease, $mandateId, $adminId);
            if (in_array($row['status'], ['invalid', 'missing'], true)) {
                return ['success' => true, 'message' => 'Mollie confirms this mandate is no longer usable.'];
            }
            if ($row['revocation'] === 'rejected') {
                return ['success' => false, 'message' => 'Mollie rejected the prior revocation. Review the mandate and permissions in the Mollie Dashboard, resolve it there, then refresh here.'];
            }
            if ($row['revocation'] !== null) {
                return ['success' => false, 'message' => 'A revocation was already requested. The provider has not confirmed an unusable state. Refresh or reconcile with Mollie; no duplicate revocation was sent.'];
            }
            if (!in_array($row['status'], ['valid', 'pending'], true)) {
                throw new \UnexpectedValueException();
            }
            // Commit before DELETE. An uncertain/crashed worker can only reconcile, never repeat the write.
            $this->mandates->write($mapping, $lease, function () use ($mapping, $mandateId, $adminId): void {
                $this->mandates->save($mapping['id'], $mandateId, ['revocation' => 'requested']);
                $this->mandates->audit($mapping['id'], $mandateId, $adminId, 'revoke_requested', 'unconfirmed');
            });
            $this->snapshots->fence($mapping, $lease);
            // A transport failure can follow a processed DELETE; disable SDK-level replay as well.
            $client->setRetryStrategy(new LinearRetryStrategy(maxRetries: 0));
            try {
                $client->send(new RevokeMandateRequest($mapping['customer_id'], $mandateId));
            } catch (ApiException $error) {
                if (in_array($error->getStatusCode(), [401, 403, 422], true)) {
                    $this->mandates->write($mapping, $lease, function () use ($mapping, $mandateId, $adminId): void {
                        $this->mandates->save($mapping['id'], $mandateId, ['revocation' => 'rejected']);
                        $this->mandates->audit($mapping['id'], $mandateId, $adminId, 'revoke', 'provider_rejected');
                    });
                }
            } catch (\Throwable) {
                // A lost response can follow a successful DELETE. Always reconcile by authenticated GET.
            }
            $row = $this->snapshots->snapshot($mapping, $client, $lease, $mandateId, $adminId);
            if ($row['revocation'] === 'rejected') {
                return ['success' => false, 'message' => 'Mollie rejected revocation. Check permissions and mandate eligibility in the Mollie Dashboard, then refresh here.'];
            }
            $confirmed = in_array($row['status'], ['invalid', 'missing'], true);

            return ['success' => $confirmed, 'message' => $confirmed ? 'Mollie confirms this mandate is no longer usable.' : 'Revocation is unconfirmed. Refresh or reconcile with Mollie before taking further action.'];
        });
    }

    private function operate(string $mappingId, ?string $mandateId, int $adminId, string $action, \Closure $operation): array
    {
        try {
            return (new CustomerService($this->di, $this->clientFactory))->withVerifiedMapping($mappingId, $operation);
        } catch (\Throwable $error) {
            // Provider exceptions can contain instruments or credentials; store only fixed categories.
            $this->mandates->connection->transactional(function () use ($mappingId, $mandateId, $adminId, $action): void {
                $this->mandates->audit($mappingId, $mandateId, $adminId, $action, 'unverified');
            });

            $unavailable = $error instanceof Support\ProviderUnavailableException || $error instanceof NetworkRequestException || $error instanceof ServerException || $error instanceof TooManyRequestsException;

            return ['success' => false, 'message' => ($unavailable ? 'Provider or customer verification is temporarily unavailable. Retry refresh later.' : 'Verification failed. Check the saved gateway, environment and customer mapping, then refresh.') . ' The displayed status is historical.'];
        }
    }

    private function mapping(string $id): array
    {
        return $this->mandates->connection->fetchAssociative('SELECT * FROM molliepayments_customer WHERE id = ?', [$id])
            ?: throw new \Payment_Exception('Customer mapping not found.', [], 404);
    }
}
