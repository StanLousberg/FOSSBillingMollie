<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$host = $argv[1] ?? '';
if (!is_file($host . '/load.php') || !is_file($host . '/di.php')) {
    fwrite(STDERR, "Usage: php diagnose-transactions.php /absolute/path/to/FOSSBilling/src\n");
    exit(1);
}
require $host . '/load.php';
$di = require $host . '/di.php';
try {
    $rows = Box\Mod\Molliepayments\Support\TransactionDiagnostics::orphans($di['em']->getConnection());
    fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) {
    fwrite(STDERR, "Could not inspect orphan transactions. No repair was attempted.\n");
    exit(1);
}
