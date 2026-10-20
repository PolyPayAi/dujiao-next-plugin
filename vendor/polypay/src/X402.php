<?php
/**
 * x402 merchant integration helper
 *
 * This helper builds standard x402 payment requirements, returns HTTP 402
 * responses for unpaid requests, and delegates verification / settlement to
 * the PolyPay facilitator API.
 *
 * @package PolyPay
 */

namespace PolyPay;

use PolyPay\Exception\ApiException;
use PolyPay\Exception\ConfigException;

class X402
{
    /** @var string Legacy header used by x402 v1 clients */
    const PAYMENT_HEADER = 'x-payment';

    /** @var string Header used by x402 v2 clients to submit a signed payment */
    const PAYMENT_SIGNATURE_HEADER = 'payment-signature';

    /** @var string Header used by x402 v2 servers to advertise payment requirements */
    const PAYMENT_REQUIRED_HEADER = 'PAYMENT-REQUIRED';

    /** @var string Header used by x402 v2 servers to return settlement details */
    const PAYMENT_RESPONSE_HEADER = 'PAYMENT-RESPONSE';

    /** @var string Legacy x402 v1 settlement response header */
    const LEGACY_PAYMENT_RESPONSE_HEADER = 'X-PAYMENT-RESPONSE';

    /** @var array<string,string> Supported Circle USDC contracts by x402 network */
    const USDC_CONTRACTS = [
        'eip155:8453' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'eip155:1' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
        'eip155:137' => '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359',
        'eip155:42161' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
        'eip155:10' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
    ];

    /** @var ApiClient API client */
    private ApiClient $client;

    /** @var array Payment requirement */
    private array $requirement;

    /** @var int x402 protocol version emitted by this helper */
    private int $protocolVersion;

    /** @var array<string,string> x402 v2 resource metadata */
    private array $resourceInfo;

    /** @var string|null Canonical protected HTTP method */
    private ?string $resourceMethod;

    /**
     * Constructor
     *
     * @param ApiClient $client  API client
     * @param array     $options x402 options:
     *   - resource (array) Payment requirement:
     *     - payTo             (string) Merchant settlement wallet address
     *     - resource          (string) Canonical protected resource URL
     *     - price             (string) Human-readable price, e.g. $0.01
     *     - amount            (string) x402 v2 token base-unit amount, optional if price is present
     *     - maxAmountRequired (string) Legacy v1 alias for amount
     *     - method            (string) HTTP method, optional
     *     - description       (string) Resource description, optional
     *     - mimeType          (string) Resource MIME type, optional
     *     - scheme            (string) Defaults to exact
     *     - network           (string) Defaults to eip155:8453
     *     - asset             (string) Defaults to USDC
     *     - assetContract     (string) Defaults to the network-specific Circle USDC contract
     *     - maxTimeoutSeconds (int) Defaults to 60
     * @throws ConfigException
     */
    public function __construct(ApiClient $client, array $options)
    {
        $resource = $options['resource'] ?? [];
        if (empty($resource['payTo'])) {
            throw new ConfigException('resource.payTo is required for x402');
        }
        if (empty($resource['resource'])) {
            throw new ConfigException('resource.resource is required for x402');
        }
        $amount = $resource['amount'] ?? $resource['maxAmountRequired'] ?? $this->amountFromUSDCPrice($resource['price'] ?? '');
        if (empty($amount)) {
            throw new ConfigException('resource.amount, resource.maxAmountRequired, or resource.price is required for x402');
        }

        $network = $resource['network'] ?? 'eip155:8453';
        $this->protocolVersion = (int)($options['protocolVersion'] ?? 2);
        if (!in_array($this->protocolVersion, [1, 2], true)) {
            throw new ConfigException('protocolVersion must be 1 or 2');
        }
        $scheme = (string)($resource['scheme'] ?? 'exact');
        if ($scheme !== 'exact') {
            throw new ConfigException('unsupported x402 scheme: ' . $scheme);
        }
        $supportedAssetContract = self::USDC_CONTRACTS[$network] ?? '';
        if ($supportedAssetContract === '') {
            throw new ConfigException('unsupported x402 network: ' . $network);
        }
        $assetContract = $resource['assetContract'] ?? null;
        if (empty($assetContract) && !empty($resource['asset']) && stripos((string)$resource['asset'], '0x') === 0) {
            $assetContract = $resource['asset'];
        }
        $assetContract = $assetContract ?? $supportedAssetContract;
        if (strcasecmp((string)$assetContract, $supportedAssetContract) !== 0) {
            throw new ConfigException('unsupported USDC contract for x402 network: ' . $network);
        }
        $asset = (string)($resource['asset'] ?? 'USDC');
        if (strcasecmp($asset, 'USDC') !== 0 && strcasecmp($asset, $supportedAssetContract) !== 0) {
            throw new ConfigException('unsupported x402 asset for network: ' . $network);
        }
        $extra = $resource['extra'] ?? [];
        if (!is_array($extra)) {
            throw new ConfigException('resource.extra must be an array');
        }
        $supportedExtra = [
            'assetTransferMethod' => 'eip3009',
            'name' => 'USD Coin',
            'version' => '2',
        ];
        foreach ($supportedExtra as $key => $expected) {
            if (array_key_exists($key, $extra) && $extra[$key] !== $expected) {
                throw new ConfigException('unsupported x402 asset metadata: ' . $key);
            }
        }

        $this->client = $client;
        $this->resourceInfo = array_filter([
            'url' => (string)$resource['resource'],
            'description' => $resource['description'] ?? null,
            'mimeType' => $resource['mimeType'] ?? null,
        ], static fn($value) => $value !== null && $value !== '');
        $configuredMethod = strtoupper(trim((string)($resource['method'] ?? '')));
        $this->resourceMethod = $configuredMethod !== '' ? $configuredMethod : null;

        if ($this->protocolVersion === 2) {
            $this->requirement = [
                'scheme' => $scheme,
                'network' => $network,
                'amount' => (string)$amount,
                'asset' => $assetContract,
                'payTo' => $resource['payTo'],
                'maxTimeoutSeconds' => (int)($resource['maxTimeoutSeconds'] ?? 60),
                'extra' => array_merge([
                    'assetTransferMethod' => 'eip3009',
                    'name' => 'USD Coin',
                    'version' => '2',
                ], $extra),
            ];
        } else {
            $this->requirement = array_merge([
                'scheme' => 'exact',
                'network' => $network,
                'asset' => 'USDC',
                'assetContract' => $assetContract,
                'maxAmountRequired' => (string)$amount,
                'maxTimeoutSeconds' => 60,
            ], $resource);
        }
    }

    /**
     * Verify and settle the current request using PAYMENT-SIGNATURE or legacy X-PAYMENT
     *
     * @param array|null  $headers HTTP headers. Defaults to current request headers.
     * @param string|null $method  Current HTTP method. Defaults to $_SERVER['REQUEST_METHOD'].
     * @param string|null $url     Canonical current request URL. Defaults to the detected request URL.
     * @return array Result with paid, required, verify, and settle keys
     * @throws ApiException
     */
    public function verifyAndSettle(?array $headers = null, ?string $method = null, ?string $url = null): array
    {
        $headers = $this->normalizeHeaders($headers ?? $this->getRequestHeaders());
        $signature = trim((string)($headers[self::PAYMENT_SIGNATURE_HEADER] ?? ''));
        $legacyPayment = trim((string)($headers[self::PAYMENT_HEADER] ?? ''));
        if ($signature !== '' && $legacyPayment !== '') {
            throw new ConfigException('provide only one x402 payment header');
        }
        $payment = $this->protocolVersion === 2 ? $signature : $legacyPayment;

        if ($payment === '') {
            return [
                'paid' => false,
                'shouldFulfill' => false,
                'required' => $this->requirementResponse(),
            ];
        }

        $requestMethod = strtoupper(trim((string)($method ?? ($_SERVER['REQUEST_METHOD'] ?? $this->resourceMethod ?? 'GET'))));
        if ($this->resourceMethod !== null && $requestMethod !== $this->resourceMethod) {
            throw new ConfigException(
                'request method does not match configured x402 resource method'
            );
        }
        $current = [
            'method' => $this->resourceMethod ?? $requestMethod,
            'resource' => $url ?? $this->resourceInfo['url'],
        ];

        $verify = $this->verify($payment, $current);
        if (empty($verify['isValid'])) {
            return [
                'paid' => false,
                'shouldFulfill' => false,
                'verify' => $verify,
                'required' => $this->requirementResponse(),
            ];
        }

        $settle = $this->settle($payment, $current);
        $replayed = !empty($settle['replayed']) || !empty($settle['extensions']['polypay']['replayed']);
        $fulfillmentKey = (string)($settle['paymentId'] ?? $settle['extensions']['polypay']['paymentId'] ?? '');
        return [
            'paid' => !empty($settle['success']),
            'shouldFulfill' => !empty($settle['success']) && !$replayed,
            'fulfillmentKey' => $fulfillmentKey !== '' ? $fulfillmentKey : null,
            'verify' => $verify,
            'settle' => $settle,
            'required' => $this->requirementResponse($settle),
            'responseHeaders' => !empty($settle['success']) ? [
                $this->protocolVersion === 2 ? self::PAYMENT_RESPONSE_HEADER : self::LEGACY_PAYMENT_RESPONSE_HEADER
                    => $this->encodeBase64Json($this->settlementResponse($settle)),
            ] : [],
        ];
    }

    /**
     * Verify an encoded x402 payment header
     *
     * @param string $payment Encoded payment payload
     * @param array  $current Current request metadata
     * @return array Verification result
     * @throws ApiException
     */
    public function verify(string $payment, array $current = []): array
    {
        $result = $this->client->verifyX402($this->buildFacilitatorPayload($payment, $current));
        $this->assertSuccess($result);
        return $result['data'] ?? [];
    }

    /**
     * Settle an encoded x402 payment header
     *
     * @param string $payment Encoded payment payload
     * @param array  $current Current request metadata
     * @return array Settlement result
     * @throws ApiException
     */
    public function settle(string $payment, array $current = []): array
    {
        $result = $this->client->settleX402($this->buildFacilitatorPayload($payment, $current));
        $this->assertSuccess($result);
        return $result['data'] ?? [];
    }

    /**
     * Build the HTTP 402 response metadata
     *
     * @param array|null $settlement Optional failed settlement for PAYMENT-RESPONSE
     * @return array Response metadata with status, headers, and body
     */
    public function requirementResponse(?array $settlement = null): array
    {
        $paymentRequired = $this->paymentRequired();
        $headers = ['Content-Type' => 'application/json'];
        if ($this->protocolVersion === 2) {
            $headers[self::PAYMENT_REQUIRED_HEADER] = $this->encodeBase64Json($paymentRequired);
        }
        if ($settlement !== null) {
            $responseHeader = $this->protocolVersion === 2
                ? self::PAYMENT_RESPONSE_HEADER
                : self::LEGACY_PAYMENT_RESPONSE_HEADER;
            $headers[$responseHeader] = $this->encodeBase64Json($this->settlementResponse($settlement));
        }
        return [
            'status' => 402,
            'headers' => $headers,
            'body' => json_encode($paymentRequired, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Send a 402 requirement response and terminate the request
     *
     * @param array|null $response Prepared response returned by verifyAndSettle, optional
     * @return void
     */
    public function sendRequirementAndExit(?array $response = null): void
    {
        $response = $response ?? $this->requirementResponse();
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response['body'];
        exit;
    }

    /**
     * Get the configured payment requirement
     *
     * @return array
     */
    public function getRequirement(): array
    {
        return $this->requirement;
    }

    /**
     * Build the PolyPay facilitator request payload
     *
     * @param string $payment Encoded payment payload
     * @param array  $current Current request metadata
     * @return array
     */
    private function buildFacilitatorPayload(string $payment, array $current): array
    {
        $decoded = $this->decodePayment($payment);
        $version = (int)($decoded['x402Version'] ?? 0);
        if (!in_array($version, [1, 2], true)) {
            throw new ConfigException('unsupported x402 payment version');
        }
        if ($version !== $this->protocolVersion) {
            throw new ConfigException(
                'payment payload version does not match configured x402 protocolVersion'
            );
        }
        return array_merge($version === 2 ? [
            'x402Version' => 2,
            'paymentPayload' => $decoded,
        ] : [
            'x402Version' => 1,
            'payment' => $payment,
        ], [
            'paymentRequirements' => $this->requirement,
            'method' => $current['method'] ?? ($this->requirement['method'] ?? ''),
            'resource' => $current['resource'] ?? ($this->requirement['resource'] ?? $this->resourceInfo['url']),
        ]);
    }

    /**
     * Assert that the API response indicates success
     *
     * @param array $result API response data
     * @return void
     * @throws ApiException
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

    /**
     * Get HTTP request headers
     *
     * @return array
     */
    private function getRequestHeaders(): array
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

    /**
     * Build the protocol-specific payment requirement.
     *
     * @return array<string,mixed>
     */
    private function paymentRequired(): array
    {
        if ($this->protocolVersion === 1) {
            return [
                'x402Version' => 1,
                'accepts' => [$this->requirement],
            ];
        }
        return [
            'x402Version' => 2,
            'error' => 'PAYMENT-SIGNATURE header is required',
            'resource' => $this->resourceInfo,
            'accepts' => [$this->requirement],
            'extensions' => new \stdClass(),
        ];
    }

    /**
     * Convert a USDC dollar price to atomic units without floating-point math.
     *
     * @param string $price Dollar price
     * @return string|null
     */
    private function amountFromUSDCPrice(string $price): ?string
    {
        $normalized = preg_replace('/^\$/', '', trim($price)) ?? '';
        if ($normalized === '' || !preg_match('/^\d+(\.\d{1,6})?$/', $normalized)) {
            return null;
        }
        $parts = explode('.', $normalized, 2);
        $whole = $parts[0];
        $fraction = str_pad($parts[1] ?? '', 6, '0');
        $amount = ltrim($whole . $fraction, '0');
        return $amount === '' ? null : $amount;
    }

    /**
     * Decode a standard or URL-safe Base64 x402 payment header.
     *
     * @param string $payment Encoded payment header
     * @return array<string,mixed>
     * @throws ConfigException
     */
    private function decodePayment(string $payment): array
    {
        $normalized = strtr(trim($payment), '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }
        $raw = base64_decode($normalized, true);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ConfigException('invalid x402 payment header');
        }
        return $decoded;
    }

    /**
     * Encode an x402 protocol object for an HTTP header.
     *
     * @param array<string,mixed> $value Protocol object
     * @return string
     */
    private function encodeBase64Json(array $value): string
    {
        return base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * Select standard x402 v2 settlement response fields.
     *
     * @param array<string,mixed> $settle PolyPay settlement result
     * @return array<string,mixed>
     */
    private function settlementResponse(array $settle): array
    {
        $response = [
            'success' => !empty($settle['success']),
            'transaction' => (string)($settle['transaction'] ?? ''),
            'network' => (string)($settle['network'] ?? ''),
        ];
        $errorReason = $settle['errorReason'] ?? $settle['invalidReason'] ?? '';
        if ($errorReason !== '') {
            $response['errorReason'] = $errorReason;
        }
        foreach (['payer', 'amount', 'extensions'] as $field) {
            if (isset($settle[$field]) && $settle[$field] !== '') {
                $response[$field] = $settle[$field];
            }
        }
        return $response;
    }

    /**
     * Normalize HTTP header names
     *
     * @param array $headers HTTP headers
     * @return array
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string)$key)] = is_array($value) ? implode(',', $value) : (string)$value;
        }
        return $normalized;
    }
}
