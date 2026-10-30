<?php

namespace App\Policies;

class ReceiptPolicy extends SalesDocumentPolicy
{
    protected function viewAllPermission(): string
    {
        return 'sales.invoices.view-all';
    }
}
