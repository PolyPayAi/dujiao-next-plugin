<?php
/**
 * API request exception
 *
 * Thrown when an API request fails, including network, HTTP, or business errors.
 *
 * @package PolyPay\Exception
 */

namespace PolyPay\Exception;

class ApiException extends PolyPayException
{
    /** @var int HTTP status code */
    private int $httpCode;

    /** @var string Raw response body */
    private string $responseBody;

    /** @var int Business error code */
    private int $apiCode;

    /**
     * Constructor
     *
     * @param string $message      Error message
     * @param int    $httpCode     HTTP status code
     * @param int    $apiCode      Business error code
     * @param string $responseBody Raw response body
     */
    public function __construct(string $message, int $httpCode = 0, int $apiCode = 0, string $responseBody = '')
    {
        parent::__construct($message, $apiCode);
        $this->httpCode = $httpCode;
        $this->apiCode = $apiCode;
        $this->responseBody = $responseBody;
    }

    /**
     * Get the HTTP status code
     *
     * @return int
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Get the raw response body
     *
     * @return string
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * Get the business error code
     *
     * @return int
     */
    public function getApiCode(): int
    {
        return $this->apiCode;
    }
}
