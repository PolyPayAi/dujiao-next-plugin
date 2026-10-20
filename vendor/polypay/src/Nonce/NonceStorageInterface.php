<?php
/**
 * Nonce storage interface
 *
 * Used for replay protection in webhook callbacks.
 * Merchants can implement this interface with Redis, a database, or any custom storage.
 *
 * @package PolyPay\Nonce
 */

namespace PolyPay\Nonce;

interface NonceStorageInterface
{
    /**
     * Consume a nonce. Returns false if the nonce already exists and has been used.
     * If the nonce does not exist, store it and return true.
     *
     * @param string $nonce Nonce value
     * @param int    $ttl   Expiration time in seconds; the nonce may be cleaned up after it expires
     * @return bool Whether the nonce was consumed successfully (true for first use, false if already used)
     */
    public function consume(string $nonce, int $ttl = 600): bool;
}
