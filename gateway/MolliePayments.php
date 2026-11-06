<?php

declare(strict_types=1);

/**
 * FOSSBilling gateway entry point.
 *
 * Payment logic and persistence belong to the companion module.
 */
class Payment_Adapter_MolliePayments extends Payment_AdapterAbstract
{
    private ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function __construct($config)
    {
        // Admin configuration validation has no callback URLs. Checkout URLs
        // are resolved afresh by PaymentService through the native host service.
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
            'supports_one_time_payments' => true,
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
        if ($subscription || filter_var($invoice_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new Payment_Exception('MolliePayments requires a valid one-time invoice payment.');
        }
        if (($this->di['loggedin_client'] ?? null) instanceof Box\Mod\Client\Entity\Client) {
            // Let the owner choose before reserving a oneoff payment, which
            // would otherwise conflict with a consenting first payment.
            $this->payments()->onboarding((int) $invoice_id);
            $url = $this->di['url']->link('molliepayments/onboarding/' . (int) $invoice_id);
        } else {
            $url = $this->payments()->checkout((int) $invoice_id, (int) $this->getParam('gateway_id'));
        }
        if ($url === null) {
            return '<p>Mollie is processing this payment. Return to the invoice to check its current status.</p>';
        }
        // getHtml is rendered in the host's normal invoice gateway view. A
        // browser navigation is needed because this response may arrive via AJAX.
        $htmlUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return '<a href="' . $htmlUrl . '">Continue</a><script>window.location.assign(' . $jsUrl . ');</script>';
    }

    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        $this->payments()->processTransaction($id, $data, $gateway_id);
    }

    private function payments(): Box\Mod\Molliepayments\PaymentService
    {
        if ($this->di === null) {
            throw new Payment_Exception('MolliePayments module is unavailable.');
        }

        return $this->di['mod_service']('Molliepayments')->payments();
    }
}
