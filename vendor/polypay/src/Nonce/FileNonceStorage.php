<?php
/**
 * Filesystem nonce storage
 *
 * Uses the local filesystem to store nonces for replay protection.
 * Suitable for low to medium traffic. Redis is recommended for high-traffic scenarios.
 *
 * @package PolyPay\Nonce
 */

namespace PolyPay\Nonce;

class FileNonceStorage implements NonceStorageInterface
{
    /** @var string Storage directory */
    private string $directory;

    /**
     * Constructor
     *
     * @param string $directory Storage directory path, defaults to the system temp directory
     */
    public function __construct(string $directory = '')
    {
        if (empty($directory)) {
            $directory = sys_get_temp_dir() . '/polypay_nonces';
        }

        $this->directory = rtrim($directory, '/');

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * Consume a nonce
     *
     * @param string $nonce Nonce value
     * @param int    $ttl   Expiration time in seconds
     * @return bool Whether the nonce was consumed successfully
     */
    public function consume(string $nonce, int $ttl = 600): bool
    {
        // Probabilistically clean up expired files to avoid scanning every request.
        if (random_int(1, 100) <= 5) {
            $this->cleanup();
        }

        $file = $this->getNonceFile($nonce);

        // Check whether the nonce file already exists.
        if (file_exists($file)) {
            // Check whether the stored nonce has expired.
            $content = file_get_contents($file);
            $expiresAt = (int)$content;
            if ($expiresAt > time()) {
                return false; // Not expired, so the nonce has already been used.
            }
            // Expired nonces can be reused.
        }

        // Atomic write with an exclusive lock.
        $fp = fopen($file, 'c');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false; // Failed to acquire the lock, likely due to a concurrent request.
        }

        // Double-check after acquiring the lock.
        $stat = fstat($fp);
        $size = $stat['size'] ?? 0;
        if ($size > 0) {
            rewind($fp);
            $existingContent = fread($fp, $size);
            if ($existingContent !== false && $existingContent !== '') {
                $expiresAt = (int)$existingContent;
                if ($expiresAt > time()) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
            }
        }

        // Store the new expiration timestamp.
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)(time() + $ttl));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * Get the nonce file path
     *
     * @param string $nonce Nonce value
     * @return string
     */
    private function getNonceFile(string $nonce): string
    {
        return $this->directory . '/' . hash('sha256', $nonce) . '.nonce';
    }

    /**
     * Clean up expired nonce files
     *
     * @return void
     */
    private function cleanup(): void
    {
        $now = time();
        $files = glob($this->directory . '/*.nonce');

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $expiresAt = (int)$content;
            if ($expiresAt > 0 && $expiresAt < $now) {
                @unlink($file);
            }
        }
    }
}
