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
        \FOSSBilling\Core\Doctrine\SchemaSynchronizer::syncEntities($this->di['em'], [Entity\MolliePaymentAttempt::class, Entity\MollieCustomer::class]);

        return true;
    }

    public function update(array $manifest = []): bool
    {
        return $this->install();
    }

    public function payments(): PaymentService
    {
        return $this->paymentFactory !== null ? ($this->paymentFactory)($this->di) : new PaymentService($this->di);
    }

    public function customers(): CustomerService
    {
        return new CustomerService($this->di);
    }

    public function getModulePermissions(): array
    {
        return ['manage_settings' => []];
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
