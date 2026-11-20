<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Molliepayments\Support\AdminTwigFactory;
use Box\Mod\Molliepayments\Support\ConnectionChecker;
use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Exception\InformationException;

final class Service implements InjectionAwareInterface
{
    private ?\Pimple\Container $di = null;

    public function __construct(
        private readonly ConnectionChecker $checker = new ConnectionChecker(),
        private readonly ?\Closure $paymentFactory = null,
    ) {
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        AdminTwigFactory::register($di);
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function install(): bool
    {
        Support\Compatibility::assertHost();
        // A symlinked module can retain the host's previous metadata cache namespace.
        $configuration = clone $this->di['em']->getConfiguration();
        $configuration->setMetadataCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $schemaManager = new \Doctrine\ORM\EntityManager($this->di['em']->getConnection(), $configuration);
        \FOSSBilling\Core\Doctrine\SchemaSynchronizer::syncEntities($schemaManager, [Entity\MolliePaymentAttempt::class, Entity\MollieCustomer::class, Entity\MollieMandate::class, Entity\MollieMandateSystemAudit::class, Entity\MollieMandateAudit::class]);

        return true;
    }

    public function update(array $manifest = []): bool
    {
        $this->install();
        $db = $this->di['em']->getConnection();
        $db->executeStatement("UPDATE molliepayments_mandate SET provider_status = status WHERE provider_status IS NULL AND status IN ('valid', 'pending', 'invalid')");
        $this->backfillMandates();
        // Older previews promoted rejected requests when a later GET observed an unusable mandate.
        $this->di['em']->getConnection()->executeStatement("UPDATE molliepayments_mandate SET revocation = 'rejected' WHERE revocation = 'confirmed' AND EXISTS (SELECT 1 FROM molliepayments_mandate_audit a WHERE a.mapping_id = molliepayments_mandate.mapping_id AND a.mandate_id = molliepayments_mandate.mandate_id AND a.action = 'revoke' AND a.outcome = 'provider_rejected')");

        return true;
    }

    private function backfillMandates(): void
    {
        $db = $this->di['em']->getConnection();
        $repository = new Repository\MandateRepository($db);
        $payments = $this->payments();
        $rows = $db->iterateAssociative("SELECT a.*, c.id AS mapping_id, c.profile_id, c.customer_id FROM molliepayments_attempt a JOIN molliepayments_customer c ON c.client_id = a.client_id AND c.gateway_id = a.gateway_id AND c.environment = a.environment LEFT JOIN molliepayments_mandate m ON m.mapping_id = c.id AND m.mandate_id = a.mandate_id WHERE m.id IS NULL AND a.status = 'paid' AND a.onboarding_status = 'ready' AND a.mandate_status = 'valid' AND a.mandate_verified_at IS NOT NULL AND a.settled_transaction_id IS NOT NULL AND a.consent_version IS NOT NULL ORDER BY a.mandate_verified_at DESC, a.id");
        foreach ($rows as $row) {
            $attempt = Repository\AttemptRepository::normalize($row);
            try {
                $payments->assertAuthorization($attempt);
                $payments->assertSettledTransaction($attempt);
                $request = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
                if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $row['mandate_id'] ?? '') || $row['customer_id'] !== ($request['customerId'] ?? null)
                    || $row['mapping_id'] !== ($request['metadata']['customer_mapping'] ?? null) || $row['profile_id'] !== ($request['metadata']['profile_id'] ?? null)) {
                    continue;
                }
            } catch (\Payment_Exception) {
                continue;
            }
            try {
                $db->transactional(function () use ($row, $repository, $db): void {
                    if ($repository->find($row['mapping_id'], $row['mandate_id']) !== null) {
                        return;
                    }
                    // Insert only. A concurrent real provider check always wins.
                    $db->insert(Repository\MandateRepository::TABLE, ['id' => bin2hex(random_bytes(16)), 'mapping_id' => $row['mapping_id'], 'mandate_id' => $row['mandate_id'],
                        'status' => 'valid', 'provider_status' => 'valid', 'scope' => 'customer-not-present', 'verified_at' => $row['mandate_verified_at'], 'checked_at' => $row['mandate_verified_at']]);
                    $repository->audit($row['mapping_id'], $row['mandate_id'], null, 'backfill', 'historical');
                });
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                if ($repository->find($row['mapping_id'], $row['mandate_id']) === null) {
                    throw new \RuntimeException('Historical mandate insert conflicted.');
                }
            }
        }
    }

    public function payments(): PaymentService
    {
        return $this->paymentFactory !== null ? ($this->paymentFactory)($this->di) : new PaymentService($this->di);
    }

    #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener]
    public function retryPendingMandates(\Box\Mod\Cron\Event\AfterAdminCronRunEvent $event): void
    {
        $this->payments()->retryPendingMandates();
    }

    #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener]
    public function prepareCustomerDeletion(\Box\Mod\Client\Event\BeforeAdminClientDeleteEvent $event): void
    {
        $this->customers()->prepareDeletion($event->clientId);
    }

    #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener]
    public function finishCustomerDeletion(\Box\Mod\Client\Event\AfterAdminClientDeleteEvent $event): void
    {
        $this->customers()->deletePending($event->clientId);
    }

    #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener]
    public function retryCustomerDeletions(\Box\Mod\Cron\Event\AfterAdminCronRunEvent $event): void
    {
        $this->customers()->deletePending();
    }

    public function customers(): CustomerService
    {
        return new CustomerService($this->di);
    }

    public function mandates(): AdminMandateService
    {
        return new AdminMandateService($this->di);
    }

    public function getModulePermissions(): array
    {
        return ['manage_settings' => [], 'view_mandates' => [], 'sync_mandates' => [], 'revoke_mandates' => []];
    }

    public function testConnection(): array
    {
        $gateways = $this->di['em']->getRepository(PayGateway::class)->findBy(['gateway' => 'MolliePayments']);
        if ($gateways === []) {
            throw new InformationException('MolliePayments gateway is not configured. Add it in Payment Gateways first.');
        }
        if (count($gateways) !== 1) {
            throw new InformationException('More than one MolliePayments gateway is configured. Keep one MolliePayments gateway to test the connection.');
        }
        $gateway = reset($gateways);
        if (!$gateway instanceof PayGateway || $gateway->getGateway() !== 'MolliePayments') {
            throw new InformationException('MolliePayments gateway not found.');
        }
        $environment = $gateway->isTestMode() ? 'test' : 'live';

        try {
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!$adapter instanceof \Payment_Adapter_MolliePayments) {
                throw new \UnexpectedValueException('Unexpected gateway adapter.');
            }
        } catch (\Throwable) {
            // Do not allow host decryption/configuration exceptions to expose
            // stored data through the API controller's exception logger.
            return ['success' => false, 'environment' => $environment, 'message' => 'The saved gateway configuration could not be loaded. Check the installation and save the credentials again.'];
        }

        return $this->checker->check($adapter, $environment);
    }
}
