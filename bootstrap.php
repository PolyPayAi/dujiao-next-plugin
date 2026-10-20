<?php
require_once __DIR__ . '/vendor/polypay/autoload.php';
require_once __DIR__ . '/src/Support.php';
require_once __DIR__ . '/src/Bridge.php';

/** Load a private configuration file and build trusted transport dependencies. */
function polypay_bridge(): array
{
    $path = getenv('POLYPAY_BRIDGE_CONFIG');
    if (!$path || !is_file($path)) throw new RuntimeException('Set POLYPAY_BRIDGE_CONFIG to a private PHP configuration file');
    $config = require $path;
    if (!is_array($config)) throw new RuntimeException('Invalid bridge configuration');
    $api = new \PolyPayPlugin\Api($config['api_key'], $config['api_url']);
    $store = new \PolyPayPlugin\Store($config['storage_dir']);
    $bridge = new \PolyPayPlugin\Bridge($config, $store, [$api, 'request'], 'polypay_notify');
    return [$bridge, $config];
}

/** Deliver only to the configured host endpoint, with TLS verification and no redirects. */
function polypay_notify(string $url, array $form): bool
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return $status >= 200 && $status < 300 && is_string($body) && trim($body) === 'success';
}

/** Emit safe errors without exposing API credentials, raw payloads, or exception internals. */
function polypay_error(Throwable $error): void
{
    $status = $error instanceof \PolyPay\Exception\SignatureException ? $error->getHttpStatus() : (int)$error->getCode();
    if (!in_array($status, [400, 401, 409, 413], true)) $status = 503;
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $status === 503 ? 'Payment service unavailable; inspect server configuration or retry delivery' : $error->getMessage()]);
}
