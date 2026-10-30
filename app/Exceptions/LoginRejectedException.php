<?php

namespace App\Exceptions;

use RuntimeException;

class LoginRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $email, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
