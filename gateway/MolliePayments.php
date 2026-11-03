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
    public function __construct($config)
    {
        // Configuration validation has no checkout callback URLs. The gateway
        // remains a stub, so do not invoke the checkout-oriented parent constructor.
        $this->_config = $config;
        $this->testMode = (bool) ($config['test_mode'] ?? false);
        if (!isset($config['gateway_id'])) {
            try {
                Box\Mod\Molliepayments\Support\Credentials::validateConfiguration($config);
            } catch (InvalidArgumentException $exception) {
                throw new Payment_Exception($exception->getMessage());
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => false,
            'supports_subscriptions' => false,
            'description' => 'Mollie Payments for FOSSBilling',
            'logo' => [
                'logo' => 'MolliePayments/assets/mollie-icon.png',
                'height' => '32px',
                'width' => '32px',
            ],
            'form' => [
                'api_key' => ['password', [
                    'label' => 'Live API Key (live_xxx)',
                    'secret' => true,
                    'required_when' => ['enabled' => true, 'test_mode' => false],
                ]],
                'test_api_key' => ['password', [
                    'label' => 'Test API Key (test_xxx)',
                    'secret' => true,
                    'required_when' => ['enabled' => true, 'test_mode' => true],
                ]],
            ],
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
