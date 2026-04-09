<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a wallet does not have enough available balance.
 */
class InsufficientBalanceException extends Exception
{
    public function __construct(string $message = 'Insufficient wallet balance.')
    {
        parent::__construct($message);
    }
}
