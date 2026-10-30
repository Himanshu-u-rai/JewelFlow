<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CapabilitiesData extends Data
{
    public function __construct(
        public bool $items,
        public bool $stock,
        public bool $customers,
        public bool $suppliers,
        public bool $purchases,
        public bool $pos,
        public bool $quick_bill,
        public bool $invoice,
        public bool $repairs,
        public bool $expenses,
        public bool $catalog,
        public bool $dashboard,
        public bool $scanner,
        public bool $schemes,
        public bool $loyalty,
        public bool $installments,
        public bool $cashbook,
        public bool $cashbook_create,
        // May add stock items (POST /items, inventory.create): the app's "Add item" button.
        public bool $items_create = false,
        // May approve / reject a pending return (returns.approve, as the web routes).
        public bool $returns_approve = false,
        // May change a repair: edit, status, deliver (repairs.edit, as the web routes).
        public bool $repairs_edit = false,
        // May add / edit / archive suppliers (vendors.manage, as the web routes).
        public bool $suppliers_manage = false,
        // May see the signed-in phones (settings.view, as the web's Settings > Devices tab).
        public bool $devices = false,
        // May make a quick bill (sales.create, as the web's quick-bills create); `quick_bill` is the list.
        public bool $quick_bill_create = false,
        // May add a customer (customers.create).
        public bool $customers_create = false,
        // May book a repair (repairs.create).
        public bool $repairs_create = false,
        // May edit a stock item (inventory.edit).
        public bool $items_edit = false,
        // May set today's rates (pricing.update, as the web's daily-rates form).
        public bool $pricing = false,
        // May record money received on a bill's due (sales.create, as the web invoice page's Record payment).
        public bool $invoice_payment = false,
    ) {}
}
