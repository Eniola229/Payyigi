<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a user tries to transact without the required KYC level.
 */
class KycRequiredException extends Exception
{
    public string $requiredLevel;

    public function __construct(string $requiredLevel = 'basic')
    {
        parent::__construct("KYC verification ({$requiredLevel}) is required to perform this action.");
        $this->requiredLevel = $requiredLevel;
    }
}
