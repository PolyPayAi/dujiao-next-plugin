<?php
/**
 * PolyPay webhook callback handler
 *
 * Verifies callback signatures from the PolyPay backend to prevent forgery and replay attacks.
 * Create instances through PolyPay::webhook().
 *
 * Signature algorithm:
 * 1. Verify that X-Key-Prefix matches the first 12 characters of the API Key
 * 2. Verify that X-Timestamp is within a 5-minute window
 * 3. Consume X-Nonce for replay protection
 * 4. Compute HMAC-SHA256(timestamp\nnonce\nraw_body, SHA256(api_key))
 * 5. Compare the result with X-Signature
 *
 * Example:
 *   $polypay = new \PolyPay\PolyPay('your-api-key');
 *   try {
 *       $data = $polypay->webhook()->handle();
 *       // Process the payment result using $data['order_no'] and $data['status']
 *       http_response_code(200);
 *       echo 'OK';
 *   } catch (\PolyPay\Exception\SignatureException $e) {
 *       http_response_code($e->getHttpStatus());
 *       echo $e->getMessage();
 *   }
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\ConfigException;
use PolyPay\Exception\SignatureException;
use PolyPay\Nonce\FileNonceStorage;
use PolyPay\Nonce\NonceStorageInterface;

class WebhookHandler
{
    /** @var int Signature time window in seconds */
    const SIGNATURE_WINDOW_SECONDS = 300;

    /** @var string API Key */
    private string $apiKey;

    /** @var NonceStorageInterface Nonce storage */
    private NonceStorageInterface $nonceStorage;

    /**
     * Constructor
     *
     * @param string                     $apiKey       API Key
     * @param NonceStorageInterface|null $nonceStorage Nonce storage implementation, defaults to file-based storage
     * @throws ConfigException
     */
    public function __construct(string $apiKey, ?NonceStorageInterface $nonceStorage = null)
    {
        if (empty(trim($apiKey))) {
            throw new ConfigException('API Key is required for webhook verification');
        }

        $this->apiKey = trim($apiKey);
        $this->nonceStorage = $nonceStorage ?? new FileNonceStorage();
    }

    /**
     * Handle the callback for the current HTTP request
     *
     * Automatically reads the request body and HTTP headers, verifies the signature,
     * and returns the parsed payload.
     *
     * @return array Verified callback payload
     * @throws SignatureException If signature verification fails
     */
    public function handle(): array
    {
        $rawBody = file_get_contents('php://input');
        $headers = $this->getHeaders();

        return $this->verify($rawBody, $headers);
    }

    /**
     * Verify a callback signature
     *
     * @param string $rawBody Raw request body
     * @param array  $headers HTTP headers, case-insensitive keys
     * @return array Verified callback payload
     * @throws SignatureException If signature verification fails
     */
    public function verify(string $rawBody, array $headers): array
    {
        // Normalize header names to support different casing styles.
        $normalizedHeaders = $this->normalizeHeaders($headers);

        $prefix = trim($normalizedHeaders['x-key-prefix'] ?? '');
        $timestamp = trim($normalizedHeaders['x-timestamp'] ?? '');
        $nonce = trim($normalizedHeaders['x-nonce'] ?? '');
        $signature = strtolower(trim($normalizedHeaders['x-signature'] ?? ''));

        // Check required signature headers.
        if ($prefix === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new SignatureException('Missing signature headers', 401);
        }

        // Validate timestamp format.
        if (!ctype_digit($timestamp)) {
            throw new SignatureException('Invalid timestamp format', 401);
        }

        // Validate the timestamp window.
        $now = time();
        $ts = (int)$timestamp;
        if (abs($now - $ts) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new SignatureException('Timestamp expired', 401);
        }

        // Validate the key prefix.
        $expectedPrefix = substr($this->apiKey, 0, 12);
        if ($prefix !== $expectedPrefix) {
            throw new SignatureException('Invalid key prefix', 401);
        }

        // Validate the nonce format.
        if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $nonce)) {
            throw new SignatureException('Invalid nonce format', 401);
        }

        // Consume the nonce for replay protection.
        $nonceKey = hash('sha256', $timestamp . '|' . $nonce);
        if (!$this->nonceStorage->consume($nonceKey, self::SIGNATURE_WINDOW_SECONDS * 2)) {
            throw new SignatureException('Nonce already used', 409);
        }

        // Compute and verify the signature.
        $keyHash = hash('sha256', $this->apiKey);
        $payload = $timestamp . "\n" . $nonce . "\n" . $rawBody;
        $expectedSignature = hash_hmac('sha256', $payload, $keyHash);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new SignatureException('Invalid signature', 401);
        }

        // Parse the request body.
        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SignatureException('Invalid request body: not valid JSON', 400);
        }

        return $data;
    }

    /**
     * Get HTTP request headers
     *
     * @return array
     */
    private function getHeaders(): array
    {
        // Prefer getallheaders() when available, such as under Apache or PHP-FPM.
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        // Fallback: extract headers from $_SERVER.
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize HTTP header names to lowercase
     *
     * @param array $headers Original header array
     * @return array Header array with lowercase keys
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
        return $normalized;
    }

    /**
     * Resolve the payment status from callback data
     *
     * PolyPay callback status codes:
     *   1 - Pending payment
     *   2 - Payment successful
     *   3 - Expired
     *   4 - Cancelled
     *   5 - Manual recharge, treated as paid
     *   6 - Confirming, treated as pending
     *
     * @param array $data Callback payload
     * @return string Normalized status: paid, pending, expired, cancelled
     */
    public static function resolveStatus(array $data): string
    {
        $status = (int)($data['status'] ?? 0);

        switch ($status) {
            case 2:
            case 5:
                return 'paid';
            case 1:
            case 6:
                return 'pending';
            case 3:
                return 'expired';
            case 4:
                return 'cancelled';
            default:
                return 'unknown';
        }
    }
}
