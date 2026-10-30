<?php

namespace App\Policies;

class InvoicePolicy extends SalesDocumentPolicy
{
    protected function viewAllPermission(): string
    {
        return 'sales.invoices.view-all';
    }
}
