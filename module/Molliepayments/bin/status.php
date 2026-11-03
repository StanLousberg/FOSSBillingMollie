<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = $argv[1] ?? '';
if (!is_file($host . '/load.php') || !is_file($host . '/di.php')) {
    fwrite(STDERR, "Usage: php status.php /absolute/path/to/FOSSBilling/src\n");
    exit(1);
}
require $host . '/load.php';
$di = require $host . '/di.php';
try {
    // Operational identifiers only: never print credentials, request URLs,
    // customer details, raw provider responses or financial account data.
    $rows = $di['em']->getConnection()->fetchAllAssociative(
        'SELECT id, invoice_id, gateway_id, payment_id, environment, status, settled_transaction_id, error
         FROM molliepayments_attempt ORDER BY created_at DESC, id DESC LIMIT 10'
    );
    fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) {
    fwrite(STDERR, "Could not inspect MolliePayments attempts. Verify the installation and schema.\n");
    exit(1);
}
