<?php

declare(strict_types=1);

class Payment_Adapter_MolliePayments extends Payment_AdapterAbstract
{
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => false,
            'supports_subscriptions' => false,
            'description' => 'MolliePayments ontwikkeladapter; betalingen zijn nog uitgeschakeld.',
            'form' => [],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        throw new Payment_Exception('MolliePayments checkout is nog niet geimplementeerd.');
    }

    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        throw new Payment_Exception('MolliePayments webhook-verwerking is nog niet geimplementeerd.');
    }
}
