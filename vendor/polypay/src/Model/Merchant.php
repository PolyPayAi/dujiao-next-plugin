<?php
/**
 * Merchant model
 *
 * @package PolyPay\Model
 */

namespace PolyPay\Model;

class Merchant
{
    /** @var string Merchant ID */
    public string $mchId;

    /** @var string Merchant name */
    public string $name;

    /** @var int Merchant status */
    public int $status;

    /** @var array Raw source data */
    public array $rawData;

    /**
     * Constructor
     *
     * @param array $data Merchant data
     */
    public function __construct(array $data = [])
    {
        $this->mchId = (string)($data['mch_id'] ?? '');
        $this->name = (string)($data['name'] ?? $data['mch_name'] ?? '');
        $this->status = (int)($data['status'] ?? 0);
        $this->rawData = $data;
    }

    /**
     * Create an instance from an API response array
     *
     * @param array $data Merchant data returned by the API
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
            'mch_id' => $this->mchId,
            'name' => $this->name,
            'status' => $this->status,
        ];
    }
}
