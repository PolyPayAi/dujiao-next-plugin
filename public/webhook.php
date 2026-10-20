<?php
require_once dirname(__DIR__) . '/bootstrap.php';
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $body = file_get_contents('php://input', false, null, 0, 1048577);
    if (!is_string($body) || strlen($body) > 1048576) throw new InvalidArgumentException('Webhook body too large', 413);
    [$bridge, $config] = polypay_bridge();
    $verifier = new \PolyPay\WebhookV2Handler($config['merchant_id'], $config['environment'],
        new \PolyPay\Nonce\FileNonceStorage($config['storage_dir'] . '/nonces'));
    $event = $verifier->verify($body, \PolyPayPlugin\Support::headers());
    // Pending, confirming and terminal unpaid events never trigger fulfillment.
    if (in_array($event['status'] ?? null, [2, 7], true)) {
        $order = $_GET['order'] ?? '';
        if (!is_string($order) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $order)) throw new InvalidArgumentException('Invalid order', 400);
        $bridge->paid($order, $event);
    }
    header('Content-Type: text/plain');
    echo 'OK';
} catch (Throwable $error) { polypay_error($error); }
