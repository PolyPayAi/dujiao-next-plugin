<?php
/**
 * Signature verification exception
 *
 * Thrown when webhook signature verification fails.
 *
 * @package PolyPay\Exception
 */

namespace PolyPay\Exception;

class SignatureException extends PolyPayException
{
    /** @var int Recommended HTTP status code to return */
    private int $httpStatus;

    /**
     * Constructor
     *
     * @param string $message    Error message
     * @param int    $httpStatus Recommended HTTP status code to return
     */
    public function __construct(string $message, int $httpStatus = 401)
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
    }

    /**
     * Get the recommended HTTP status code
     *
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
