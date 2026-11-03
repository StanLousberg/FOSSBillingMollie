<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

final class Service
{
    public function install(): bool
    {
        // Reserved for module-owned Doctrine entities and tables.
        // Never create or alter FOSSBilling core tables here.
        return true;
    }
}
