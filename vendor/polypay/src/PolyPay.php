<?php
/**
 * Main PolyPay SDK facade
 *
 * Merchants can use this class to perform all payment operations.
 *
 * Example:
 *   $polypay = new \PolyPay\PolyPay('your-api-key');
 *   $checkoutUrl = $polypay->createCheckoutUrl([...]);
 *   $order = $polypay->createOrder([...]);
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\ApiException;
use PolyPay\Model\Merchant;
use PolyPay\Model\Order;
use PolyPay\Model\PaymentMethod;
use PolyPay\Nonce\NonceStorageInterface;

class PolyPay
{
    /** @var string API Key */
    private string $apiKey;

    /** @var ApiClient API client */
    private ApiClient $client;

    /**
     * Constructor
     *
     * @param string $apiKey  API Key, required
     * @param array  $options Optional configuration:
     *   - api_url        (string) API base URL, defaults to https://api.polypay.ai
     *   - timeout        (int)    Timeout in seconds, defaults to 30
     *   - debug          (bool)   Whether debug logging is enabled, defaults to false
     *   - debug_log_file (string) Debug log file path
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = $apiKey;
        $this->client = new ApiClient(
            $apiKey,
            $options['api_url'] ?? '',
            $options['timeout'] ?? 30,
            $options['debug'] ?? false,
            $options['debug_log_file'] ?? null
        );
    }

    /**
     * Get payment methods available to the merchant
     *
     * @return PaymentMethod[] Payment method list
     * @throws ApiException
     */
    public function getPaymentMethods(): array
    {
        $result = $this->client->getPaymentMethods();
        $this->assertSuccess($result);

        $methods = [];
        foreach (($result['data']['methods'] ?? []) as $item) {
            $methods[] = PaymentMethod::fromArray($item);
        }

        return $methods;
    }

    /**
     * Build a hosted checkout URL.
     *
     * PolyPay hosts the payment method selection page. If currency and network
     * are included, hosted checkout skips selection and opens the payment page.
     *
     * @param array $params Checkout parameters:
     *   - public_key   (string) Merchant public key, required
     *   - amount       (float|string) Order amount, required
     *   - timestamp    (int|string) Millisecond timestamp, optional
     *   - signature    (string) Signature, optional; generated when omitted
     *   - order_id     (string) Merchant order ID, optional
     *   - notify_url   (string) Webhook callback URL, optional
     *   - redirect_url (string) Redirect URL after payment, optional
     *   - currency     (string) Payment currency, optional
     *   - network      (string) Payment network, optional
     *   - contract     (string) Payment contract, optional
     * @param array $options Optional configuration:
     *   - checkout_url (string) Hosted checkout origin, defaults to https://checkout.polypay.ai
     *   - locale       (string) Optional locale path segment; omit to detect browser language
     * @return string Hosted checkout URL
     * @throws ApiException
     */
    public static function buildCheckoutUrl(array $params, array $options = []): string
    {
        $params = self::normalizeCheckoutParams($params);

        if (($params['public_key'] ?? '') === '') {
            throw new ApiException('public_key is required');
        }
        if (($params['amount'] ?? '') === '') {
            throw new ApiException('amount is required');
        }

        $params['timestamp'] = (string)($params['timestamp'] ?? self::currentMilliseconds());
        $params['amount'] = is_numeric($params['amount'])
            ? number_format((float)$params['amount'], 2, '.', '')
            : (string)$params['amount'];

        if (($params['signature'] ?? '') === '') {
            $params['signature'] = self::generateCheckoutSignature($params, $params['public_key']);
        }

        $checkoutUrl = rtrim($options['checkout_url'] ?? 'https://checkout.polypay.ai', '/');
        $locale = trim($options['locale'] ?? 'en');
        if ($locale === '') {
            $locale = 'en';
        }

        $query = array_filter($params, static function ($value): bool {
            return $value !== null && $value !== '';
        });

        return $checkoutUrl . '/' . rawurlencode($locale) . '/checkout?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Generate hosted checkout signature.
     *
     * @param array  $params    Checkout parameters
     * @param string $publicKey Merchant public key
     * @return string HMAC-SHA256 signature
     */
    public static function generateCheckoutSignature(array $params, string $publicKey): string
    {
        $params = self::normalizeCheckoutParams($params);
        if (isset($params['amount']) && is_numeric($params['amount'])) {
            $params['amount'] = number_format((float)$params['amount'], 2, '.', '');
        }
        unset($params['signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $payload = implode('&', $pairs) . '&key=' . $publicKey;
        return hash_hmac('sha256', $payload, $publicKey);
    }

    /**
     * Create a payment order
     *
     * @param array $params Order parameters:
     *   - mch_order_id (string) Merchant order ID, required
     *   - currency     (string) Currency such as USDT or USDC, required
     *   - network      (string) Network such as tron, ethereum, bsc, solana, or polygon, required
     *   - amount       (float)  Amount, required
     *   - notify_url   (string) Webhook callback URL, required
     *   - redirect_url (string) Redirect URL after payment, optional
     * @return Order Order object
     * @throws ApiException
     */
    public function createOrder(array $params): Order
    {
        $result = $this->client->createOrder($params);
        $this->assertSuccess($result);

        return Order::fromArray($result['data'] ?? []);
    }

    /**
     * Create a hosted checkout URL using API Key Mode.
     *
     * This method keeps the API Key on the merchant server. Omit currency and
     * network to show PolyPay's hosted payment method selection page.
     *
     * @param array $params Checkout parameters
     * @return string Hosted checkout URL
     * @throws ApiException
     */
    public function createCheckoutUrl(array $params): string
    {
        $result = $this->client->createCheckout($params);
        $this->assertSuccess($result);

        $data = $result['data'] ?? [];
        $checkoutUrl = (string)($data['checkout_url'] ?? $data['payment_url'] ?? '');
        if ($checkoutUrl === '') {
            throw new ApiException('checkout_url is missing from API response');
        }

        return $checkoutUrl;
    }

    /**
     * Normalize hosted checkout parameter aliases.
     *
     * @param array $params Raw checkout parameters
     * @return array Normalized checkout parameters
     */
    private static function normalizeCheckoutParams(array $params): array
    {
        $aliases = [
            'publicKey' => 'public_key',
            'orderId' => 'order_id',
            'redirectUrl' => 'redirect_url',
            'notifyUrl' => 'notify_url',
        ];

        foreach ($aliases as $from => $to) {
            if (array_key_exists($from, $params) && !array_key_exists($to, $params)) {
                $params[$to] = $params[$from];
            }
            unset($params[$from]);
        }

        return $params;
    }

    /**
     * Get current Unix timestamp in milliseconds.
     *
     * @return string Timestamp in milliseconds
     */
    private static function currentMilliseconds(): string
    {
        return (string)intval(round(microtime(true) * 1000));
    }

    /**
     * Query order details by trade ID
     *
     * @param string $tradeId Trade ID
     * @return Order Order object
     * @throws ApiException
     */
    public function getOrderByTradeId(string $tradeId): Order
    {
        $result = $this->client->getOrderDetail($tradeId);
        $this->assertSuccess($result);

        return Order::fromArray($result['data'] ?? []);
    }

    /**
     * Query order details by merchant order ID
     *
     * @param string $mchOrderId Merchant order ID
     * @return Order Order object
     * @throws ApiException
     */
    public function getOrderByMchOrderId(string $mchOrderId): Order
    {
        $result = $this->client->getOrderDetail('', $mchOrderId);
        $this->assertSuccess($result);

        return Order::fromArray($result['data'] ?? []);
    }

    /**
     * Get merchant details
     *
     * @return Merchant Merchant object
     * @throws ApiException
     */
    public function getMerchantDetail(): Merchant
    {
        $result = $this->client->getMerchantDetail();
        $this->assertSuccess($result);

        return Merchant::fromArray($result['data'] ?? []);
    }

    /**
     * Activate a plugin, typically called when the API Key is configured for the first time
     *
     * @param string $pluginType Plugin type identifier
     * @return bool Whether activation succeeded
     * @throws ApiException
     */
    public function activatePlugin(string $pluginType = 'php-sdk'): bool
    {
        $result = $this->client->activatePlugin($pluginType);
        return isset($result['code']) && $result['code'] == 0;
    }

    /**
     * Create a webhook handler that shares the same API Key
     *
     * @param NonceStorageInterface|null $nonceStorage Custom nonce storage, optional
     * @return WebhookHandler
     */
    public function webhook(?NonceStorageInterface $nonceStorage = null): WebhookHandler
    {
        return new WebhookHandler($this->apiKey, $nonceStorage);
    }

    /**
     * Create a strict Webhook v2 Ed25519 verifier.
     *
     * This verifier uses PolyPay public keys and never falls back to API Key verification.
     *
     * @param string                                 $expectedMerchantId Expected signed merchant ID
     * @param string                                 $environment        Expected signed environment
     * @param NonceStorageInterface|null             $nonceStorage       Custom replay-protection storage
     * @param WebhookPublicKeyProviderInterface|null $keyProvider        Custom public key provider
     * @return WebhookV2Handler
     */
    public function webhookV2(
        string $expectedMerchantId,
        string $environment = 'production',
        ?NonceStorageInterface $nonceStorage = null,
        ?WebhookPublicKeyProviderInterface $keyProvider = null
    ): WebhookV2Handler {
        return new WebhookV2Handler($expectedMerchantId, $environment, $nonceStorage, $keyProvider);
    }

    /**
     * Create an x402 helper for agent payments
     *
     * @param array $options x402 configuration
     * @return X402
     */
    public function x402(array $options): X402
    {
        return new X402($this->client, $options);
    }

    /**
     * Get the underlying API client for advanced use cases
     *
     * @return ApiClient
     */
    public function getApiClient(): ApiClient
    {
        return $this->client;
    }

    /**
     * Assert that the API response indicates success
     *
     * @param array $result API response data
     * @throws ApiException If the business code is not 0
     */
    private function assertSuccess(array $result): void
    {
        if (!isset($result['code']) || $result['code'] != 0) {
            throw new ApiException(
                $result['message'] ?? 'API request failed',
                200,
                (int)($result['code'] ?? -1),
                json_encode($result, JSON_UNESCAPED_UNICODE) ?: ''
            );
        }
    }
}
