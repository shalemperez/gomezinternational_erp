<?php

namespace App\Exceptions;

class GmailNotConnectedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
    }
}
