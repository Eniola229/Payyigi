<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a transaction PIN is wrong or locked.
 */
class TransactionPinException extends Exception
{
    public bool $isLocked;
    public int $attemptsRemaining;

    public function __construct(
        string $message = 'Invalid transaction PIN.',
        bool $isLocked = false,
        int $attemptsRemaining = 0
    ) {
        parent::__construct($message);
        $this->isLocked          = $isLocked;
        $this->attemptsRemaining = $attemptsRemaining;
    }
}
