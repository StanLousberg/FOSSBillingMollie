<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Controller;

use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Http\App;
use Pimple\Container;

final class Client implements InjectionAwareInterface
{
    private ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function register(App &$app): void
    {
        $app->get('/molliepayments/onboarding/:invoice', 'get_onboarding', ['invoice' => '[0-9]+'], static::class);
    }

    public function get_onboarding(App $app, $invoice): string
    {
        $this->di['is_client_logged'];
        $service = $this->di['mod_service']('Molliepayments');
        $data = $service->payments()->onboarding((int) $invoice);

        return $app->render('mod_molliepayments_onboarding', ['onboarding' => $data]);
    }
}
