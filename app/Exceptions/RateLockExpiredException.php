<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a user tries to confirm a sell order after the rate lock has expired.
 */
class RateLockExpiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('The rate lock has expired. Please initiate a new order to get a fresh rate.');
    }
}
