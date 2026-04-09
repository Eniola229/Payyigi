<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an operation is attempted on a frozen or suspended wallet.
 */
class WalletFrozenException extends Exception
{
    public function __construct(string $message = 'Your wallet is currently frozen. Please contact support.')
    {
        parent::__construct($message);
    }
}
