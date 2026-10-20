<?php
/**
 * Order model
 *
 * @package PolyPay\Model
 */

namespace PolyPay\Model;

class Order
{
    /** @var string Trade ID */
    public string $tradeId;

    /** @var string Payment page URL */
    public string $paymentUrl;

    /** @var float Order amount */
    public float $amount;

    /** @var float Actual paid amount, which may differ slightly due to amount suffix rules */
    public float $actualAmount;

    /** @var string Payment address */
    public string $address;

    /** @var int|null Expiration timestamp in Unix seconds */
    public ?int $expiresAt;

    /** @var string Currency */
    public string $currency;

    /** @var string Network */
    public string $network;

    /** @var string Status */
    public string $status;

    /** @var string Transaction hash */
    public string $txHash;

    /** @var string Merchant order ID */
    public string $mchOrderId;

    /**
     * Constructor
     *
     * @param array $data Order data
     */
    public function __construct(array $data = [])
    {
        $this->tradeId = (string)($data['trade_id'] ?? '');
        $this->paymentUrl = (string)($data['payment_url'] ?? '');
        $this->amount = (float)($data['amount'] ?? 0);
        $this->actualAmount = (float)($data['actual_amount'] ?? 0);
        $this->address = (string)($data['address'] ?? '');
        $this->expiresAt = isset($data['expires_at']) ? (int)$data['expires_at'] : null;
        $this->currency = (string)($data['currency'] ?? '');
        $this->network = (string)($data['network'] ?? '');
        $this->status = (string)($data['status'] ?? '');
        $this->txHash = (string)($data['tx_hash'] ?? $data['transaction_id'] ?? '');
        $this->mchOrderId = (string)($data['mch_order_id'] ?? $data['order_no'] ?? '');
    }

    /**
     * Create an instance from an API response array
     *
     * @param array $data Order data returned by the API
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Convert the model to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'trade_id' => $this->tradeId,
            'payment_url' => $this->paymentUrl,
            'amount' => $this->amount,
            'actual_amount' => $this->actualAmount,
            'address' => $this->address,
            'expires_at' => $this->expiresAt,
            'currency' => $this->currency,
            'network' => $this->network,
            'status' => $this->status,
            'tx_hash' => $this->txHash,
            'mch_order_id' => $this->mchOrderId,
        ];
    }
}
