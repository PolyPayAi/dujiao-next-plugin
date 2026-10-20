<?php
require_once dirname(__DIR__) . '/bootstrap.php';
// No credentials are accepted on the command line or printed to output.
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !in_array($argv[1], ['retry', 'reconcile', 'cancel'], true)) {
        throw new InvalidArgumentException('Usage: php bin/payment.php retry|reconcile|cancel HOST_PAYMENT_ORDER');
    }
    [$bridge] = polypay_bridge();
    if ($argv[1] === 'retry') { $bridge->retry($argv[2]); echo "delivered\n"; }
    else echo json_encode($bridge->maintain($argv[2], $argv[1] === 'cancel')) . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
