<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Controller;

use FOSSBilling\Core\Container\InjectionAwareInterface;
use FOSSBilling\Core\Http\App;
use Pimple\Container;

final class Admin implements InjectionAwareInterface
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
        $app->get('/molliepayments', 'get_settings', [], static::class);
        $app->get('/molliepayments/mandates', 'get_index', [], static::class);
        $app->get('/molliepayments/customers/:mapping', 'get_customer', ['mapping' => '[a-f0-9]{32}'], static::class);
        $app->get('/molliepayments/mandates/:mapping/:mandate', 'get_detail', ['mapping' => '[a-f0-9]{32}', 'mandate' => 'mdt_[A-Za-z0-9]{1,124}'], static::class);
    }

    public function fetchNavigation(): array
    {
        return ['subpages' => [['location' => 'extensions', 'label' => 'MolliePayments mandates',
            'uri' => $this->di['url']->adminLink('molliepayments/mandates'), 'index' => 700, 'class' => '']]];
    }

    public function get_settings(App $app): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('Extension')->hasManagePermission('molliepayments');

        return $app->redirect('/extension/settings/molliepayments');
    }

    public function get_index(App $app): string
    {
        $this->authorize();

        return $app->render('mod_molliepayments_mandates');
    }

    public function get_customer(App $app, string $mapping): string
    {
        $this->authorize();

        return $app->render('mod_molliepayments_customer', ['mapping_id' => $mapping]);
    }

    public function get_detail(App $app, string $mapping, string $mandate): string
    {
        $this->authorize();

        return $app->render('mod_molliepayments_mandate', ['mapping_id' => $mapping, 'mandate_id' => $mandate]);
    }

    private function authorize(): void
    {
        $this->di['is_admin_logged'];
        $staff = $this->di['mod_service']('Staff');
        $staff->checkPermissionsAndThrowException('molliepayments', 'view_mandates');
        $staff->checkPermissionsAndThrowException('client', 'view');
        // Register the module fallback before native render, including symlinked installations.
        $this->di['mod_service']('Molliepayments');
    }
}
