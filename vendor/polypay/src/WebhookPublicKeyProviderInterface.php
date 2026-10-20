<?php
/**
 * Webhook v2 public key provider.
 *
 * @package PolyPay
 */

namespace PolyPay;

interface WebhookPublicKeyProviderInterface
{
    /**
     * Return the raw 32-byte Ed25519 public key for a key ID.
     *
     * @param string $keyId Webhook signing key ID
     * @return string Raw Ed25519 public key bytes
     */
    public function getPublicKey(string $keyId): string;
}
