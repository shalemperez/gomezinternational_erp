<?php

namespace App\Policies;

class ProformaPolicy extends SalesDocumentPolicy
{
    protected function viewAllPermission(): string
    {
        return 'sales.proformas.view-all';
    }
}
