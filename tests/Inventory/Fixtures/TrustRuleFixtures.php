<?php

/**
 * Fixtures for tests/Inventory/tenant_query_sweep.php's trust rules — parsed,
 * never loaded or run. Run the scanner over this directory with
 * `--path=tests/Inventory/Fixtures --all`.
 *
 * Every expression is on one line ending in its expected outcome:
 *   `// expect: review`  the scanner must list it as a row to read
 *   `// expect: trusted` the scanner may exclude it (the control case)
 *
 * The namespace imitates a controller because one rule (a route-bound model
 * of a scoped class) applies only to controller actions. Autoloading maps
 * App\ to app/, so this file is never loaded as a class.
 */

namespace App\Http\Controllers\InventoryFixture;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrustRuleFixtures
{
    // ── operators: only equality constrains ──────────────────────────────────
    public function negatedOperator(Request $request)
    {
        return DB::table('invoices')->where('shop_id', '<>', $request->user()->shop_id)->get(); // expect: review
    }

    public function notEqualOperator(Request $request)
    {
        return DB::table('invoices')->where('shop_id', '!=', $request->user()->shop_id)->get(); // expect: review
    }

    public function whereNot(Request $request)
    {
        return DB::table('invoices')->whereNot('shop_id', $request->user()->shop_id)->get(); // expect: review
    }

    public function equalityControl(Request $request)
    {
        return DB::table('invoices')->where('shop_id', '=', $request->user()->shop_id)->get(); // expect: trusted
    }

    // ── an assigned shop_id constrains no row ────────────────────────────────
    public function unfilteredUpdate(Request $request)
    {
        return DB::table('invoices')->update(['shop_id' => $request->user()->shop_id]); // expect: review
    }

    public function updateOrCreateValues(Request $request)
    {
        return DB::table('invoices')->updateOrInsert(['invoice_number' => 'X'], ['shop_id' => $request->user()->shop_id]); // expect: review
    }

    public function insertControl(Request $request)
    {
        return DB::table('invoices')->insert(['shop_id' => $request->user()->shop_id, 'invoice_number' => 'X']); // expect: trusted
    }

    // ── a record's key is trusted only when the record's ownership is ────────
    public function recordFromAnUnscopedLookup(Request $request)
    {
        $invoice = Invoice::withoutTenant()->find($request->integer('invoice_id')); // expect: review

        return DB::table('invoice_payments')->where('shop_id', $invoice->shop_id)->get(); // expect: review
    }

    public function keyFromAnUnscopedRecord(Request $request)
    {
        $customer = Customer::withoutTenant()->find($request->integer('customer_id')); // expect: review

        return DB::table('loyalty_transactions')->where('customer_id', $customer->id)->get(); // expect: review
    }

    public function keyThroughARelation(Request $request)
    {
        $invoice = Invoice::findOrFail($request->integer('invoice_id'));

        return DB::table('customers')->where('id', $invoice->customer->id)->get(); // expect: review
    }

    public function recordFromAScopedLookupControl(Request $request)
    {
        $invoice = Invoice::findOrFail($request->integer('invoice_id'));

        return DB::table('invoice_payments')->where('shop_id', $invoice->shop_id)->get(); // expect: trusted
    }

    public function routeBoundScopedModelControl(Customer $customer)
    {
        return DB::table('loyalty_transactions')->where('customer_id', $customer->id)->get(); // expect: trusted
    }
}
