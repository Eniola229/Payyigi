<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when 2FA verification is required before proceeding.
 */
class TwoFactorRequiredException extends Exception
{
    public string $otpToken;
    public string $method; // 'google' or 'sms'

    public function __construct(string $otpToken, string $method = 'google')
    {
        parent::__construct('Two-factor authentication is required.');
        $this->otpToken = $otpToken;
        $this->method   = $method;
    }
}
