<?php

namespace App\Policies;

use App\Models\KarigarInvoice;
use App\Models\User;

class KarigarInvoicePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('karigar_invoice.manage');
    }

    public function view(User $user, KarigarInvoice $invoice): bool
    {
        return $user->shop_id === $invoice->shop_id;
    }

    public function update(User $user, KarigarInvoice $invoice): bool
    {
        return $user->shop_id === $invoice->shop_id
            && $user->hasPermission('karigar_invoice.manage');
    }

    public function delete(User $user, KarigarInvoice $invoice): bool
    {
        return $user->shop_id === $invoice->shop_id
            && $user->hasPermission('karigar_invoice.manage');
    }
}
