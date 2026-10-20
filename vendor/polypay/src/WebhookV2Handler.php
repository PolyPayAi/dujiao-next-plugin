<?php
/**
 * Strict Ed25519 verifier for PolyPay Webhook signature version v2.
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\ConfigException;
use PolyPay\Exception\SignatureException;
use PolyPay\Nonce\FileNonceStorage;
use PolyPay\Nonce\NonceStorageInterface;

class WebhookV2Handler
{
    public const SIGNATURE_WINDOW_SECONDS = 300;
    private const PAYLOAD_DOMAIN = 'polypay-webhook-v2';

    private string $expectedMerchantId;
    private string $expectedEnvironment;
    private NonceStorageInterface $nonceStorage;
    private WebhookPublicKeyProviderInterface $keyProvider;

    /** Initialize a strict v2 verifier with an expected signed audience. */
    public function __construct(
        string $expectedMerchantId,
        string $expectedEnvironment = 'production',
        ?NonceStorageInterface $nonceStorage = null,
        ?WebhookPublicKeyProviderInterface $keyProvider = null
    ) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new ConfigException('The sodium extension is required for Webhook v2 verification');
        }
        $this->expectedMerchantId = trim($expectedMerchantId);
        $this->expectedEnvironment = trim($expectedEnvironment);
        if ($this->expectedMerchantId === '' || $this->expectedEnvironment === '') {
            throw new ConfigException('Expected merchant ID and environment are required for Webhook v2 verification');
        }
        $this->nonceStorage = $nonceStorage ?? new FileNonceStorage();
        $this->keyProvider = $keyProvider ?? new HttpJwksKeyProvider();
    }

    /** Verify the current HTTP request and return its decoded JSON body. */
    public function handle(): array
    {
        $rawBody = file_get_contents('php://input');
        return $this->verify(is_string($rawBody) ? $rawBody : '', $this->getHeaders());
    }

    /** Verify one Webhook v2 request without any fallback to legacy verification. */
    public function verify(string $rawBody, array $headers): array
    {
        $headers = $this->normalizeHeaders($headers);
        $version = trim((string)($headers['x-webhook-signature-version'] ?? ''));
        $keyId = trim((string)($headers['x-webhook-key-id'] ?? ''));
        $timestamp = trim((string)($headers['x-timestamp'] ?? ''));
        $nonce = trim((string)($headers['x-nonce'] ?? ''));
        $merchantId = trim((string)($headers['x-webhook-merchant-id'] ?? ''));
        $environment = trim((string)($headers['x-webhook-environment'] ?? ''));
        $encodedSignature = trim((string)($headers['x-webhook-signature-v2'] ?? ''));

        if ($version !== 'v2') {
            throw new SignatureException('Unsupported webhook signature version', 401);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)
            || !ctype_digit($timestamp)
            || !preg_match('/^[A-Za-z0-9]{16,128}$/', $nonce)
        ) {
            throw new SignatureException('Invalid Webhook v2 signature headers', 401);
        }
        if (!hash_equals($this->expectedMerchantId, $merchantId)
            || !hash_equals($this->expectedEnvironment, $environment)
        ) {
            throw new SignatureException('Invalid webhook audience', 401);
        }
        if (abs(time() - (int)$timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new SignatureException('Timestamp expired', 401);
        }

        $signature = self::decodeBase64Url($encodedSignature);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new SignatureException('Invalid Webhook v2 signature encoding', 401);
        }
        $publicKey = $this->keyProvider->getPublicKey($keyId);
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new SignatureException('Invalid webhook verification key', 503);
        }
        $payload = implode("\n", [
            self::PAYLOAD_DOMAIN,
            $keyId,
            $timestamp,
            $nonce,
            $merchantId,
            $environment,
            $rawBody,
        ]);
        if (!sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new SignatureException('Invalid Webhook v2 signature', 401);
        }

        $nonceKey = hash('sha256', 'v2|' . $keyId . '|' . $timestamp . '|' . $nonce);
        if (!$this->nonceStorage->consume($nonceKey, self::SIGNATURE_WINDOW_SECONDS * 2)) {
            throw new SignatureException('Nonce already used', 409);
        }
        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new SignatureException('Invalid request body: not valid JSON', 400);
        }
        return $data;
    }

    /** Read request headers across Apache and PHP-FPM environments. */
    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        return $headers;
    }

    /** Normalize HTTP header names to lowercase. */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string)$key)] = $value;
        }
        return $normalized;
    }

    /** Decode unpadded Base64url data. */
    private static function decodeBase64Url(string $encoded): ?string
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return null;
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($decoded) ? $decoded : null;
    }
}
