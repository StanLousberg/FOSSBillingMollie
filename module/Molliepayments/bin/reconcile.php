<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = $argv[1] ?? '';
if (!is_file($host . '/load.php') || !is_file($host . '/di.php') || count($argv) !== 4) {
    fwrite(STDERR, "Usage: php reconcile.php /absolute/path/to/FOSSBilling/src ATTEMPT_ID PAYMENT_ID\n");
    exit(1);
}
require $host . '/load.php';
$di = require $host . '/di.php';
try {
    $id = $di['mod_service']('Molliepayments')->payments()->reconcile($argv[2], $argv[3]);
    fwrite(STDOUT, "MolliePayments reconciliation handled by native transaction {$id}. Inspect its status.\n");
} catch (Throwable) {
    fwrite(STDERR, "MolliePayments reconciliation failed. Inspect the saved attempt and native transaction error; no unverified payment is accepted.\n");
    exit(1);
}
