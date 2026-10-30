<?php

namespace App\Policies;

class CreditNotePolicy extends SalesDocumentPolicy
{
    protected function viewAllPermission(): string
    {
        return 'sales.invoices.view-all';
    }
}
