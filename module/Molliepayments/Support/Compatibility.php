<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** Released 0.8.9 lacks these preview-host contracts; version strings alone are insufficient. */
final class Compatibility
{
    public static function assertHost(): void
    {
        $requirements = [
            \FOSSBilling\Core\Doctrine\SchemaSynchronizer::class => ['syncEntities'],
            \FOSSBilling\Core\Doctrine\NamedLock::class => ['acquire', 'release'],
            \FOSSBilling\Core\Http\PaymentReturn::class => ['createResponse'],
            \Box\Mod\Client\ServiceBalance::class => ['getClientBalanceForUpdate'],
            \Box\Mod\Invoice\ServiceTransaction::class => ['claimForProcessing', 'createAndProcess'],
            \Box\Mod\Invoice\Service::class => ['lockInvoiceState', 'markAsPaid', 'getPaymentInvoice', 'resolveRenewalLine'],
            \Box\Mod\Product\Service::class => ['getRenewalPromoAdjustments'],
            \Box\Mod\Invoice\ServicePayGateway::class => ['getPaymentAdapter', 'getSecretFields'],
        ];
        foreach ($requirements as $class => $methods) {
            foreach ($methods as $method) {
                if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
                    throw new \Payment_Exception('Unsupported FOSSBilling build. MolliePayments requires the documented Doctrine, balance-locking and payment-return contracts.', [], 409);
                }
            }
        }
        if ((new \ReflectionMethod(\Box\Mod\Invoice\Service::class, 'markAsPaid'))->getNumberOfParameters() < 4) {
            throw new \Payment_Exception('This FOSSBilling build cannot defer payment events safely.', [], 409);
        }
    }
}
