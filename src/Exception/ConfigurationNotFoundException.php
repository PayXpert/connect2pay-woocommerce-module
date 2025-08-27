<?php

namespace Payxpert\Exception;

/**
 * Exception thrown when a requested configuration is not found.
 */
class ConfigurationNotFoundException extends PayxpertException
{
    /**
     * ConfigurationNotFoundException constructor.
     *
     * @param string $message Custom error message (default: 'Configuration not found.')
     * @param int $code Error code (default: 0)
     * @param \Exception|null $previous Previous exception if nested exception (default: null)
     */
    public function __construct(string $message = 'Configuration not found.', int $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
