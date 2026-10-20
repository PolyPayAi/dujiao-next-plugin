<?php
/**
 * HTTP-backed PolyPay Webhook JWKS provider.
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\SignatureException;

class HttpJwksKeyProvider implements WebhookPublicKeyProviderInterface
{
    public const DEFAULT_JWKS_URL = 'https://api.polypay.ai/api/v1/pay/public/webhook-jwks';

    /** @var string JWKS endpoint */
    private string $jwksUrl;

    /** @var int HTTP timeout in seconds */
    private int $timeout;

    /** @var array<string,string> In-memory raw public key cache */
    private array $keys = [];

    /**
     * Initialize the JWKS provider.
     *
     * @param string $jwksUrl HTTPS JWKS endpoint
     * @param int    $timeout HTTP timeout in seconds
     */
    public function __construct(string $jwksUrl = self::DEFAULT_JWKS_URL, int $timeout = 5)
    {
        $parts = parse_url($jwksUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new SignatureException('Webhook JWKS URL must use HTTPS', 500);
        }
        $this->jwksUrl = $jwksUrl;
        $this->timeout = max(1, $timeout);
    }

    /** Get one raw Ed25519 public key by key ID. */
    public function getPublicKey(string $keyId): string
    {
        if (!isset($this->keys[$keyId])) {
            $this->refresh();
        }
        if (!isset($this->keys[$keyId])) {
            throw new SignatureException('Unknown webhook signing key', 401);
        }
        return $this->keys[$keyId];
    }

    /** Download, validate, and replace the in-memory JWKS cache. */
    private function refresh(): void
    {
        $handle = curl_init($this->jwksUrl);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new SignatureException('Unable to fetch webhook verification keys' . ($error ? ': ' . $error : ''), 503);
        }

        $document = json_decode($body, true);
        if (!is_array($document)) {
            throw new SignatureException('Invalid webhook JWKS response', 503);
        }
        $keys = $document['keys'] ?? ($document['data']['keys'] ?? null);
        if (!is_array($keys)) {
            throw new SignatureException('Invalid webhook JWKS response', 503);
        }

        $validated = [];
        foreach ($keys as $key) {
            if (!is_array($key)
                || ($key['kty'] ?? '') !== 'OKP'
                || ($key['crv'] ?? '') !== 'Ed25519'
                || ($key['alg'] ?? '') !== 'EdDSA'
                || ($key['use'] ?? '') !== 'sig'
                || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string)($key['kid'] ?? ''))
            ) {
                continue;
            }
            $publicKey = self::decodeBase64Url((string)($key['x'] ?? ''));
            if ($publicKey !== null && strlen($publicKey) === 32) {
                $validated[(string)$key['kid']] = $publicKey;
            }
        }
        if ($validated === []) {
            throw new SignatureException('Webhook JWKS contains no valid keys', 503);
        }
        $this->keys = $validated;
    }

    /** Decode unpadded Base64url data, rejecting non-canonical input. */
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
