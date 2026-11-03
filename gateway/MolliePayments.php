<?php

declare(strict_types=1);

/**
 * FOSSBilling gateway entry point.
 *
 * This is an intentional development-only stub. Never enable payment
 * capabilities until the checkout, webhook, reconciliation and recurring
 * payment flows have been implemented and tested.
 */
class Payment_Adapter_MolliePayments extends Payment_AdapterAbstract
{
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => false,
            'supports_subscriptions' => false,
            'description' => 'MolliePayments development adapter. Payment processing is not implemented.',
            'form' => [],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        throw new Payment_Exception('MolliePayments checkout is not implemented yet.');
    }

    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        throw new Payment_Exception('MolliePayments webhook handling is not implemented yet.');
    }
}
