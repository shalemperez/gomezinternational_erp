<?php

namespace App\Exceptions;

class GmailNotConnectedException extends \RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
    }
}
