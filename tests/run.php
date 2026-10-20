<?php
require_once dirname(__DIR__) . '/bootstrap.php';
use PolyPayPlugin\Bridge;
use PolyPayPlugin\Store;
use PolyPayPlugin\Support;

/** Fail immediately on a protocol or state-machine regression. */
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
/** Assert a rejected input does not silently become a successful payment. */
function rejects(callable $action): void {
    try { $action(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Expected rejection');
}
$dir = sys_get_temp_dir() . '/polypay-bridge-test-' . bin2hex(random_bytes(8));
$store = new Store($dir);
$config = ['pid' => 'test', 'epay_key' => str_repeat('k', 32), 'merchant_id' => 'MCH_TEST', 'environment' => 'sandbox',
    'base_url' => 'https://bridge.example', 'notify_url' => 'https://shop.example/api/v1/payments/callback',
    'return_url' => 'https://shop.example/pay', 'usd_rate' => '0.14'];
$creates = 0; $deliveries = 0; $ack = false; $remoteStatus = 1; $cancelCalls = 0; $ambiguous = false;
$api = function ($op, $params) use (&$creates, &$remoteStatus, &$cancelCalls, &$ambiguous): array {
    if ($op === 'checkout') {
        ++$creates;
        check(strlen($params['mch_order_id']) <= 32, 'Merchant order ID limit');
        check($params['amount'] === '1.40', 'USD conversion');
        if ($ambiguous) throw new RuntimeException('Network lost after creation');
        return ['checkout_url' => 'https://checkout.polypay.ai/pay/T1'];
    }
    if ($op === 'cancel') { ++$cancelCalls; $remoteStatus = 4; return []; }
    return ['trade_id' => 'T1', 'status' => $remoteStatus, 'fiat_amount' => '1.40'];
};
$notify = function ($url, $form) use (&$deliveries, &$ack, $config): bool {
    ++$deliveries;
    check($url === $config['notify_url'], 'Callback pinned');
    check(Bridge::sign($form, $config['epay_key']) === $form['sign'], 'Callback signature');
    check($form['money'] === '10.00' && $form['trade_status'] === 'TRADE_SUCCESS', 'Host accounting');
    return $ack;
};
$bridge = new Bridge($config, $store, $api, $notify);
$input = ['pid' => 'test', 'type' => 'alipay', 'out_trade_no' => 'ORDER1', 'money' => '10.00',
    'name' => 'Test', 'notify_url' => $config['notify_url'], 'return_url' => $config['return_url'] . '?order_id=1'];
$input['sign'] = Bridge::sign($input, $config['epay_key']); $input['sign_type'] = 'MD5';
$record = $bridge->checkout($input);
check($bridge->checkout($input) === $record && $creates === 1, 'Checkout retry must reuse attempt');
$changedConfig = $config; $changedConfig['usd_rate'] = '0.20';
check((new Bridge($changedConfig, $store, $api, $notify))->checkout($input) === $record, 'Persist locked pricing');
$wrongMerchant = $config; $wrongMerchant['merchant_id'] = 'OTHER';
rejects(function () use ($wrongMerchant, $store, $api, $notify, $input) { (new Bridge($wrongMerchant, $store, $api, $notify))->checkout($input); });
$bad = $input; $bad['money'] = '9.00'; rejects(function () use ($bridge, $bad) { $bridge->checkout($bad); });
$bad = $input; $bad['notify_url'] = 'https://attacker.example'; $bad['sign'] = Bridge::sign($bad, $config['epay_key']);
rejects(function () use ($bridge, $bad) { $bridge->checkout($bad); });
$event = ['order_no' => $record['mch_order_id'], 'fiat_amount' => '1.40', 'status' => 2, 'event_id' => 'evt1', 'environment' => 'sandbox'];
foreach (['status' => 5, 'fiat_amount' => '0.01', 'order_no' => 'OTHER', 'environment' => 'production'] as $field => $value) {
    $bad = $event; $bad[$field] = $value;
    rejects(function () use ($bridge, $bad) { $bridge->paid('ORDER1', $bad); });
}
check($deliveries === 0, 'Rejected events cannot notify host');
rejects(function () use ($bridge, $event) { $bridge->paid('ORDER1', $event); });
check($store->read('ORDER1')['state'] === 'paid' && !$store->read('ORDER1')['notified'], 'Failed delivery is durably retryable');
$ack = true; $bridge->retry('ORDER1'); $bridge->paid('ORDER1', $event);
check($deliveries === 2 && $store->read('ORDER1')['notified'], 'Duplicate events do not repeat acknowledged delivery');
$remoteStatus = 1; check($bridge->maintain('ORDER1', true)['state'] === 'paid' && $cancelCalls === 0, 'Never cancel paid');
$input['out_trade_no'] = 'ORDER2'; $input['sign'] = Bridge::sign($input, $config['epay_key']);
$remoteStatus = 1; $bridge->checkout($input);
check($bridge->maintain('ORDER2', true)['state'] === 'cancelled' && $cancelCalls === 1, 'Cancel convergence');
rejects(function () use ($bridge, $input) { $bridge->checkout($input); });
$input['out_trade_no'] = 'ORDER3'; $input['sign'] = Bridge::sign($input, $config['epay_key']); $ambiguous = true;
rejects(function () use ($bridge, $input) { $bridge->checkout($input); });
check($store->read('ORDER3')['usd_amount'] === '1.40', 'Ambiguous creation preserves pricing intent');
$ambiguous = false; (new Bridge($changedConfig, $store, $api, $notify))->checkout($input);
check(Support::usd('1.00', '0.145') === '0.15', 'Decimal half-up rounding');
rejects(function () { Support::url('https://shop.example\\@attacker.example'); });
rejects(function () { Support::amount('1e2'); });

// Verify real Ed25519 vectors using the shipped SDK, including audience and replay protection.
$pair = sodium_crypto_sign_keypair(); $public = sodium_crypto_sign_publickey($pair); $secret = sodium_crypto_sign_secretkey($pair);
$provider = new class($public) implements \PolyPay\WebhookPublicKeyProviderInterface {
    private string $key;
    /** Use a local signing key so tests cannot contact production JWKS. */
    public function __construct(string $key) { $this->key = $key; }
    /** Select only the fixture key ID. */
    public function getPublicKey(string $id): string { if ($id !== 'test') throw new RuntimeException('Unknown key'); return $this->key; }
};
$verifier = new \PolyPay\WebhookV2Handler('MCH_TEST', 'sandbox', new \PolyPay\Nonce\FileNonceStorage($dir . '/nonces'), $provider);
$body = json_encode($event); $timestamp = (string)time(); $nonce = bin2hex(random_bytes(16));
$payload = implode("\n", ['polypay-webhook-v2', 'test', $timestamp, $nonce, 'MCH_TEST', 'sandbox', $body]);
$signature = rtrim(strtr(base64_encode(sodium_crypto_sign_detached($payload, $secret)), '+/', '-_'), '=');
$headers = ['X-Webhook-Signature-Version' => 'v2', 'X-Webhook-Key-Id' => 'test', 'X-Timestamp' => $timestamp,
    'X-Nonce' => $nonce, 'X-Webhook-Merchant-Id' => 'MCH_TEST', 'X-Webhook-Environment' => 'sandbox', 'X-Webhook-Signature-V2' => $signature];
$bad = $headers; $bad['X-Webhook-Environment'] = 'production'; rejects(function () use ($verifier, $body, $bad) { $verifier->verify($body, $bad); });
rejects(function () use ($verifier, $body, $headers) { $verifier->verify($body . ' ', $headers); });
$bad = $headers; $bad['X-Timestamp'] = (string)(time() - 600); rejects(function () use ($verifier, $body, $bad) { $verifier->verify($body, $bad); });
check($verifier->verify($body, $headers) === $event, 'Valid v2 vector');
rejects(function () use ($verifier, $body, $headers) { $verifier->verify($body, $headers); });
echo "PASS: checkout, pricing, signatures, replay, durable retry, duplicates, cancellation, ambiguous creation\n";
