<?php
/** Shared payment primitives vendored by the standalone PolyPay adapters. */
namespace PolyPayPlugin;

class Support
{
    /** Validate and normalize an amount without binary floating-point arithmetic. */
    public static function amount($value): string
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new \InvalidArgumentException('Invalid amount', 400);
        }
        $amount = bcadd((string)$value, '0', 2);
        if (bccomp($amount, '0', 2) <= 0) throw new \InvalidArgumentException('Amount must be positive', 400);
        return $amount;
    }

    /** Convert to USD using the configured USD-per-source-currency rate. */
    public static function usd(string $amount, string $rate): string
    {
        if (!preg_match('/^\d{1,6}(?:\.\d{1,10})?$/D', $rate) || bccomp($rate, '0', 10) <= 0) {
            throw new \InvalidArgumentException('Configure a positive USD conversion rate', 500);
        }
        return self::amount(bcadd(bcmul($amount, $rate, 12), '0.005', 2));
    }

    /** Require an HTTPS URL without credentials, fragments, or control characters. */
    public static function url(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('A valid HTTPS URL is required', 400);
        }
        return $url;
    }

    /** Read HTTP headers in PHP-FPM as well as Apache. */
    public static function headers(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
        return $headers;
    }

    /** Authenticate and normalize a paid event against its immutable pricing snapshot. */
    public static function paid(array $event, array $record): void
    {
        if (!in_array($event['status'] ?? null, [2, 7], true)) {
            throw new \UnexpectedValueException('Event is not a paid order', 409);
        }
        foreach (['order_no' => 'mch_order_id'] as $field => $stored) {
            if (empty($event[$field]) || !hash_equals((string)$record[$stored], (string)$event[$field])) {
                throw new \UnexpectedValueException($field . ' mismatch', 409);
            }
        }
        if (empty($event['event_id']) || ($event['environment'] ?? '') !== $record['environment']
            || self::amount($event['fiat_amount'] ?? '') !== $record['usd_amount']) {
            throw new \UnexpectedValueException('Payment amount, environment or event ID mismatch', 409);
        }
    }
}

class Store
{
    private string $directory;

    /** Open private durable storage outside the public document root. */
    public function __construct(string $directory)
    {
        if ($directory === '' || $directory[0] !== '/') throw new \RuntimeException('An absolute storage directory is required');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create storage');
        $resolved = realpath($directory);
        $public = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        if (!$resolved || ($public && ($resolved === $public || strpos($resolved, $public . '/') === 0))) {
            throw new \RuntimeException('Storage must be outside the public document root');
        }
        $this->directory = $resolved;
    }

    /** Serialize operations on one order with a stable, separate lock file. */
    public function locked(string $id, callable $action)
    {
        $path = $this->path($id) . '.lock';
        $handle = fopen($path, 'c');
        if (!$handle || !flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock order');
        chmod($path, 0600);
        try { return $action($this->read($id)); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Load a record; a malformed record is an error, never a new order. */
    public function read(string $id): array
    {
        $path = $this->path($id);
        if (!file_exists($path)) return [];
        $record = json_decode(file_get_contents($path), true);
        if (!is_array($record)) throw new \RuntimeException('Corrupt payment record');
        return $record;
    }

    /** Atomically replace a record while the caller holds the order lock. */
    public function save(string $id, array $record): void
    {
        $path = $this->path($id);
        $temporary = tempnam($this->directory, '.payment-');
        if ($temporary === false) throw new \RuntimeException('Cannot allocate payment record');
        try {
            chmod($temporary, 0600);
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $json) !== strlen($json)) throw new \RuntimeException('Cannot write payment record');
            $handle = fopen($temporary, 'r+');
            if ($handle && function_exists('fsync')) fsync($handle);
            if ($handle) fclose($handle);
            if (!rename($temporary, $path)) throw new \RuntimeException('Cannot commit payment record');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    /** Derive a traversal-safe filename from an opaque order identifier. */
    private function path(string $id): string { return $this->directory . '/' . hash('sha256', $id) . '.json'; }
}

class Api
{
    private string $key;
    private string $base;

    /** Keep the API Key exclusively on the trusted server. */
    public function __construct(string $key, string $base = 'https://api.polypay.ai')
    {
        if (trim($key) === '') throw new \RuntimeException('PolyPay API Key is required');
        $this->key = $key;
        $this->base = rtrim(Support::url($base), '/');
    }

    /** Call canonical merchant endpoints and preserve the full order mapping. */
    public function request(string $operation, array $parameters): array
    {
        if (!in_array($operation, ['checkout', 'detail', 'cancel'], true)) throw new \InvalidArgumentException('Unknown API operation');
        $handle = curl_init($this->base . '/api/v1/pay/order/' . $operation);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($parameters, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $this->key],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) throw new \RuntimeException('PolyPay HTTP request failed');
        $response = json_decode($body, true);
        if (!is_array($response) || !array_key_exists('code', $response) || (int)$response['code'] !== 0
            || !is_array($response['data'] ?? null)) throw new \RuntimeException('PolyPay rejected the request');
        return $response['data'];
    }
}
