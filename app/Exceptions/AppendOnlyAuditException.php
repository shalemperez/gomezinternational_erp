<?php

namespace App\Exceptions;

use RuntimeException;

class AppendOnlyAuditException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La bitácora de auditoría es de solo escritura');
    }
}
