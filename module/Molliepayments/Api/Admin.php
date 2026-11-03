<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Api;

use Box\Mod\Staff\Entity\Admin as AdminIdentity;
use FOSSBilling\Core\Api\AbstractApi;
use FOSSBilling\Core\Exception\InformationException;

final class Admin extends AbstractApi
{
    public function connection_test(array $data = []): array
    {
        $this->requireGatewayPermission();

        return $this->getService()->testConnection();
    }

    private function requireGatewayPermission(): void
    {
        if (!$this->identity instanceof AdminIdentity) {
            throw new InformationException('Administrator authentication is required.', [], 403);
        }
        $this->checkPermissions('invoice', 'manage_gateways');
    }
}
