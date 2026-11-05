<?php

declare(strict_types=1);

// Module upgrades have no public update action in the current host. This CLI
// invokes the same module-owned, additive schema lifecycle as installation.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = $argv[1] ?? '';
if (!is_file($host . '/load.php') || !is_file($host . '/di.php')) {
    fwrite(STDERR, "Usage: php migrate.php /absolute/path/to/FOSSBilling/src\n");
    exit(1);
}

require $host . '/load.php';
$di = require $host . '/di.php';
try {
    $di['mod_service']('Molliepayments')->update();
    fwrite(STDOUT, "MolliePayments schema is up to date. Audit records are retained.\n");
} catch (Throwable) {
    fwrite(STDERR, "MolliePayments schema migration failed. Verify host compatibility and database schema permissions.\n");
    exit(1);
}
