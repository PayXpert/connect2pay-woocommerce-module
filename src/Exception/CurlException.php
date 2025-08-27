<?php

namespace Payxpert\Exception;

/**
 * Exception thrown when a requested configuration is not found.
 */
class CurlException extends PayxpertException
{
    /**
     * ConfigurationNotFoundException constructor.
     *
     * @param string $message Custom error message
     * @param int $code Error code (default: 0)
     * @param \Exception|null $previous Previous exception if nested exception (default: null)
     */
    public function __construct(string $message = 'You need to enable the cURL extension to use this module.', int $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
