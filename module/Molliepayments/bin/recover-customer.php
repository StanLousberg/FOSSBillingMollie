<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = $argv[1] ?? '';
if (count($argv) !== 6 || !is_file($host . '/load.php') || !is_file($host . '/di.php')
    || !ctype_digit($argv[2]) || !ctype_digit($argv[3])) {
    fwrite(STDERR, "Usage: php recover-customer.php /absolute/path/to/FOSSBilling/src CLIENT_ID GATEWAY_ID MAPPING_ID CUSTOMER_ID\n");
    exit(1);
}
require $host . '/load.php';
$di = require $host . '/di.php';
try {
    $di['mod_service']('Molliepayments')->customers()->recover((int) $argv[2], (int) $argv[3], $argv[4], $argv[5]);
    fwrite(STDOUT, "Customer recovery verified and saved. Creation identity and history were preserved.\n");
} catch (Throwable) {
    fwrite(STDERR, "Customer recovery failed. Check the saved context and provider metadata. No replacement was created.\n");
    exit(1);
}
