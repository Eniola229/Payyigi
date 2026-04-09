<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Breet API returns an error or is unreachable.
 */
class BreetApiException extends Exception
{
    public int $statusCode;
    public array $breetResponse;

    public function __construct(
        string $message = 'A payment processing error occurred. Please try again.',
        int $statusCode = 500,
        array $breetResponse = []
    ) {
        parent::__construct($message);
        $this->statusCode    = $statusCode;
        $this->breetResponse = $breetResponse;
    }
}
