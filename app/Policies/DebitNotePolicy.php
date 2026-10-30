<?php

namespace App\Policies;

class DebitNotePolicy extends SalesDocumentPolicy
{
    protected function viewAllPermission(): string
    {
        return 'sales.invoices.view-all';
    }
}
