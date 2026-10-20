<?php
require dirname(__DIR__) . '/bootstrap.php';

/** Require rejected settings or a tampered signed URL to fail. */
function rejectsHttpConfig(callable $action): void
{
    try { $action(); } catch (\InvalidArgumentException $error) { return; }
    throw new \RuntimeException('Expected URL validation failure');
}
$directory = sys_get_temp_dir() . '/polypay-bridge-http-' . bin2hex(random_bytes(8));
$store = new \PolyPayPlugin\Store($directory);
$config = ['pid' => 'fixture', 'epay_key' => str_repeat('k', 32), 'merchant_id' => 'MCH_TEST', 'environment' => 'production',
    'base_url' => 'http://192.168.31.96:18083', 'notify_url' => 'http://192.168.31.96:18082/api/v1/payments/callback',
    'return_url' => 'http://192.168.31.96:18082/pay', 'usd_rate' => '1'];
$api = function (string $operation, array $parameters) use ($config): array {
    if ($operation === 'checkout') {
        if ($parameters['notify_url'] !== $config['base_url'] . '/webhook.php?order=LAN1') throw new \RuntimeException('Bridge callback origin changed');
        return ['checkout_url' => 'https://checkout.polypay.ai/pay/fixture'];
    }
    return ['trade_id' => 'fixture'];
};
$notify = static function (): bool { return true; };
try {
    rejectsHttpConfig(function () use ($config, $store, $api, $notify) { new \PolyPayPlugin\Bridge($config, $store, $api, $notify); });
    $config['force_https'] = 0;
    $bridge = new \PolyPayPlugin\Bridge($config, $store, $api, $notify);
    $input = ['pid' => 'fixture', 'type' => 'alipay', 'out_trade_no' => 'LAN1', 'money' => '1.00', 'notify_url' => $config['notify_url'],
        'return_url' => $config['return_url'] . '?order_no=TEST', 'sign_type' => 'MD5'];
    $input['sign'] = \PolyPayPlugin\Bridge::sign($input, $config['epay_key']);
    if ($bridge->checkout($input)['checkout_url'] !== 'https://checkout.polypay.ai/pay/fixture') throw new \RuntimeException('HTTP host checkout failed');
    $input['notify_url'] = 'http://192.168.31.97/callback'; $input['sign'] = \PolyPayPlugin\Bridge::sign($input, $config['epay_key']);
    rejectsHttpConfig(function () use ($bridge, $input) { $bridge->checkout($input); });
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
echo "PASS: HTTPS default, explicit HTTP testing, pinned host callback, HTTPS PolyPay checkout\n";
