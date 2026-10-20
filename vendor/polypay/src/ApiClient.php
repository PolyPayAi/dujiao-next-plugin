<?php
/**
 * PolyPay HTTP API client
 *
 * Wraps all HTTP interactions with the PolyPay backend API using cURL
 * with zero external dependencies.
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\ApiException;
use PolyPay\Exception\ConfigException;

class ApiClient
{
    /** @var string SDK version */
    const VERSION = '1.2.0';

    /** @var string Default API base URL */
    const DEFAULT_API_URL = 'https://api.polypay.ai';

    /** @var string API Key */
    private string $apiKey;

    /** @var string API base URL */
    private string $apiUrl;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /** @var bool Whether debug logging is enabled */
    private bool $debug;

    /** @var string|null Debug log file path */
    private ?string $debugLogFile;

    /** @var array Debug context for the most recent request */
    private array $lastDebugContext = [];

    /**
     * Constructor
     *
     * @param string      $apiKey       API Key
     * @param string      $apiUrl       API base URL, defaults to production
     * @param int         $timeout      Timeout in seconds
     * @param bool        $debug        Whether debug logging is enabled
     * @param string|null $debugLogFile Debug log file path
     * @throws ConfigException If the API Key is empty
     */
    public function __construct(
        string $apiKey,
        string $apiUrl = '',
        int $timeout = 30,
        bool $debug = false,
        ?string $debugLogFile = null
    ) {
        if (empty(trim($apiKey))) {
            throw new ConfigException('API Key is required');
        }

        $this->apiKey = trim($apiKey);
        $this->apiUrl = rtrim($apiUrl ?: self::DEFAULT_API_URL, '/');
        $this->timeout = $timeout;
        $this->debug = $debug;
        $this->debugLogFile = $debugLogFile;
    }

    /**
     * Get payment methods available to the merchant
     *
     * @return array API response data
     * @throws ApiException
     */
    public function getPaymentMethods(): array
    {
        return $this->request('/api/v1/pay/sdk/payment-methods', []);
    }

    /**
     * Create a payment order
     *
     * @param array $params Order parameters:
     *   - mch_order_id (string) Merchant order ID
     *   - currency     (string) Currency such as USDT or USDC
     *   - network      (string) Network such as tron, ethereum, or bsc
     *   - amount       (float)  Amount
     *   - notify_url   (string) Webhook callback URL
     *   - redirect_url (string) Redirect URL after payment, optional
     * @return array API response data
     * @throws ApiException
     */
    public function createOrder(array $params): array
    {
        return $this->request('/api/v1/pay/sdk/order/add', $params);
    }

    /**
     * Create a hosted checkout URL with API Key authentication.
     *
     * Omit currency and network to let PolyPay hosted checkout show the
     * payment method selection page. Include both fields to skip selection.
     *
     * @param array $params Checkout parameters:
     *   - mch_order_id (string) Merchant order ID
     *   - amount       (float)  Amount
     *   - notify_url   (string) Webhook callback URL
     *   - redirect_url (string) Redirect URL after payment, optional
     *   - currency     (string) Currency, optional
     *   - network      (string) Network, optional
     *   - locale       (string) Checkout locale path segment, optional
     * @return array API response data
     * @throws ApiException
     */
    public function createCheckout(array $params): array
    {
        return $this->request('/api/v1/pay/sdk/order/checkout', $params);
    }

    /**
     * Query order details
     *
     * @param string $tradeId    Trade ID, one of the two identifiers is required
     * @param string $mchOrderId Merchant order ID, one of the two identifiers is required
     * @return array API response data
     * @throws ApiException
     */
    public function getOrderDetail(string $tradeId = '', string $mchOrderId = ''): array
    {
        $params = [];
        if (!empty($tradeId)) {
            $params['trade_id'] = $tradeId;
        }
        if (!empty($mchOrderId)) {
            $params['mch_order_id'] = $mchOrderId;
        }
        return $this->request('/api/v1/pay/sdk/order/detail', $params);
    }

    /**
     * Get merchant details
     *
     * @return array API response data
     * @throws ApiException
     */
    public function getMerchantDetail(): array
    {
        return $this->request('/api/v1/pay/sdk/merchant/detail', []);
    }

    /**
     * Activate a plugin
     *
     * @param string $pluginType Plugin type, defaults to php-sdk
     * @return array API response data
     * @throws ApiException
     */
    public function activatePlugin(string $pluginType = 'php-sdk'): array
    {
        return $this->request('/api/v1/pay/sdk/plugin/activate', [
            'plugin_type' => $pluginType,
        ]);
    }

    /**
     * Verify an x402 payment payload
     *
     * @param array $params x402 verification payload
     * @return array API response data
     * @throws ApiException
     */
    public function verifyX402(array $params): array
    {
        return $this->request('/api/v1/pay/x402/verify', $params);
    }

    /**
     * Settle an x402 payment payload
     *
     * @param array $params x402 settlement payload
     * @return array API response data
     * @throws ApiException
     */
    public function settleX402(array $params): array
    {
        return $this->request('/api/v1/pay/x402/settle', $params);
    }

    /**
     * Get debug context for the most recent request
     *
     * @return array
     */
    public function getLastDebugContext(): array
    {
        return $this->lastDebugContext;
    }

    /**
     * Send an API request
     *
     * @param string $endpoint API endpoint path
     * @param array  $data     Request payload
     * @return array Decoded response data
     * @throws ApiException
     */
    private function request(string $endpoint, array $data = []): array
    {
        $url = $this->apiUrl . $endpoint;
        $requestId = $this->generateRequestId();
        $jsonBody = json_encode($data, JSON_UNESCAPED_UNICODE);

        $this->lastDebugContext = [
            'request_id' => $requestId,
            'endpoint' => $endpoint,
            'url' => $url,
            'timeout' => $this->timeout,
            'request_body' => $data,
            'api_key_prefix' => substr($this->apiKey, 0, 12),
        ];

        $this->writeDebugLog("[{$requestId}] Request URL: {$url}");
        $this->writeDebugLog("[{$requestId}] API Key Prefix: " . substr($this->apiKey, 0, 12) . '...');
        $this->writeDebugLog("[{$requestId}] Request Body: {$jsonBody}");

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'User-Agent: PolyPay-PHP-SDK/' . self::VERSION,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        // Handle cURL transport errors.
        if ($curlErrno !== 0) {
            $this->lastDebugContext['curl_errno'] = $curlErrno;
            $this->lastDebugContext['curl_error'] = $curlError;
            $this->writeDebugLog("[{$requestId}] cURL Error [{$curlErrno}]: {$curlError}");

            throw new ApiException(
                "Network error: {$curlError}",
                0,
                0,
                ''
            );
        }

        $this->lastDebugContext['http_code'] = $httpCode;
        $this->lastDebugContext['response_body'] = $responseBody;
        $this->writeDebugLog("[{$requestId}] HTTP Code: {$httpCode}");
        $this->writeDebugLog("[{$requestId}] Response Body: {$responseBody}");

        // Handle HTTP-level errors.
        if ($httpCode !== 200) {
            $decoded = json_decode($responseBody, true);
            $errorMsg = $decoded['message'] ?? "HTTP Error {$httpCode}";

            $this->writeDebugLog("[{$requestId}] API Error: {$errorMsg}");

            throw new ApiException(
                $errorMsg,
                $httpCode,
                (int)($decoded['code'] ?? 0),
                (string)$responseBody
            );
        }

        // Decode the JSON response body.
        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonErrorMsg = json_last_error_msg();
            $this->writeDebugLog("[{$requestId}] JSON Decode Error: {$jsonErrorMsg}");

            throw new ApiException(
                "Invalid JSON response: {$jsonErrorMsg}",
                $httpCode,
                0,
                (string)$responseBody
            );
        }

        $this->lastDebugContext['decoded'] = $decoded;
        $this->writeDebugLog("[{$requestId}] API Code: " . ($decoded['code'] ?? 'null'));
        $this->writeDebugLog("[{$requestId}] API Message: " . ($decoded['message'] ?? ''));

        return $decoded;
    }

    /**
     * Generate a request ID
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }

    /**
     * Write a debug log entry
     *
     * @param string $message Log message
     * @return void
     */
    private function writeDebugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        $logFile = $this->debugLogFile ?: sys_get_temp_dir() . '/polypay-sdk-debug.log';
        $line = gmdate('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND);
    }
}
