<?php
require_once dirname(__DIR__) . '/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { http_response_code(405); exit; }
    [$bridge] = polypay_bridge();
    $record = $bridge->checkout($_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST);
    header('Location: ' . $record['checkout_url'], true, 303);
} catch (Throwable $error) { polypay_error($error); }
