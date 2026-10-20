<?php
/**
 * Payment method model
 *
 * @package PolyPay\Model
 */

namespace PolyPay\Model;

class PaymentMethod
{
    /** @var string Network name, such as Tron, Ethereum, or BSC */
    public string $network;

    /** @var string[] Supported currency list, such as USDT or USDC */
    public array $currencies;

    /**
     * Constructor
     *
     * @param string   $network    Network name
     * @param string[] $currencies Currency list
     */
    public function __construct(string $network, array $currencies = [])
    {
        $this->network = $network;
        $this->currencies = $currencies;
    }

    /**
     * Create an instance from an API response array
     *
     * @param array $data Payment method data returned by the API
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['network'] ?? '',
            $data['currencies'] ?? []
        );
    }

    /**
     * Convert the model to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'currencies' => $this->currencies,
        ];
    }
}
