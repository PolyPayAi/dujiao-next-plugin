<?php
// Copy outside the public document root; never commit the populated file.
return [
    'pid' => 'polypay',
    'epay_key' => '', // Generate: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'api_key' => '',
    'api_url' => 'https://api.polypay.ai',
    'merchant_id' => '',
    'environment' => 'production',
    'base_url' => 'https://bridge.example.com',
    'notify_url' => 'https://shop.example.com/api/v1/payments/callback',
    'return_url' => 'https://shop.example.com/pay',
    'usd_rate' => '', // USD per one unit submitted by Dujiao-Next. Use 1 for USD.
    'storage_dir' => '/var/lib/polypay-dujiao-next',
];
