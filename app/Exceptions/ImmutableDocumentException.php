<?php

namespace App\Exceptions;

class ImmutableDocumentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('El documento ya fue emitido y no se puede modificar');
    }
}
