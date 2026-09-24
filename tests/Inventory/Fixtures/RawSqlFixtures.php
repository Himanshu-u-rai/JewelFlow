<?php

/**
 * Fixtures for the scanner's raw-SQL table identification — parsed, never
 * loaded or run (see TrustRuleFixtures.php for the marker format). A marker
 * may name the tables the scanner must find, in order of appearance
 * (`tables=items,job_orders` after the outcome).
 * `-` is a statement that names no table; `?` a table the scanner cannot
 * identify. `global` is a query on known platform-wide tables only.
 *
 * The first five reproduce the inventory's actual failures: EXTRACT(… FROM
 * NOW() …) read as a table named `now`, and only the first table of a
 * statement recorded (DetectStuckStates S1–S5, ReconcileKarigarBalances B).
 */

namespace App\Http\Controllers\InventoryFixture;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RawSqlFixtures
{
    // ── the actual failures: every shop in a loop (console-style) ────────────
    public function extractFromNow(array $shopIds)
    {
        foreach ($shopIds as $shopId) {
            $rows[] = DB::select( // expect: review tables=items
                "SELECT i.id, EXTRACT(DAY FROM NOW() - i.updated_at)::int AS days_stuck
                 FROM items i
                 WHERE i.shop_id = ? AND i.updated_at < NOW() - INTERVAL '14 days'", [$shopId]);
        }

        return $rows;
    }

    public function extractAndASubquery(array $shopIds)
    {
        foreach ($shopIds as $shopId) {
            $rows[] = DB::select( // expect: review tables=items,job_orders
                "SELECT i.id, EXTRACT(DAY FROM NOW() - i.updated_at)::int AS days_stuck
                 FROM items i
                 WHERE i.shop_id = ?
                   AND NOT EXISTS (SELECT 1 FROM job_orders jo WHERE jo.source_item_id = i.id)", [$shopId]);
        }

        return $rows;
    }

    public function extractAndAJoin(array $shopIds)
    {
        foreach ($shopIds as $shopId) {
            $rows[] = DB::select( // expect: review tables=job_orders,karigars
                "SELECT jo.id, k.name AS karigar_name, EXTRACT(DAY FROM NOW() - jo.updated_at)::int AS days_stuck
                 FROM job_orders jo
                 JOIN karigars k ON k.id = jo.karigar_id
                 WHERE jo.shop_id = ?", [$shopId]);
        }

        return $rows;
    }

    public function aLeftJoinAfterTheFirstTable(array $shopIds)
    {
        foreach ($shopIds as $shopId) {
            $rows[] = DB::select( // expect: review tables=return_orders,customers
                "SELECT ro.id, COALESCE(c.first_name || ' ' || c.last_name, 'Walk-in') AS customer_name
                 FROM return_orders ro
                 LEFT JOIN customers c ON c.id = ro.customer_id
                 WHERE ro.shop_id = ? AND ro.status = 'draft'", [$shopId]);
        }

        return $rows;
    }

    public function extractFromAnIssueDate(array $shopIds, int $days)
    {
        foreach ($shopIds as $shopId) {
            $rows[] = DB::select( // expect: review tables=job_orders,karigars
                "SELECT jo.id, k.name AS karigar_name, EXTRACT(DAY FROM NOW() - jo.issue_date)::int AS days_open
                 FROM job_orders jo
                 JOIN karigars k ON k.id = jo.karigar_id
                 WHERE jo.shop_id = ? AND jo.issue_date < NOW() - (INTERVAL '1 day' * ?)", [$shopId, $days]);
        }

        return $rows;
    }

    // ── controls and the other forms the parser must get right ───────────────
    public function extractOnOneTableOfTheCallersShop()
    {
        return DB::select( // expect: trusted tables=items
            "SELECT i.id, EXTRACT(EPOCH FROM NOW() - i.updated_at) AS age FROM items i WHERE i.shop_id = ? FOR UPDATE",
            [auth()->user()->shop_id]);
    }

    public function twoTenantTablesOneFilter()
    {
        // Which table the filter constrains is not something the scanner can tell.
        return DB::select( // expect: review tables=invoices,customers
            'SELECT i.id, c.first_name FROM invoices i, customers c WHERE c.id = i.customer_id AND i.shop_id = ?',
            [auth()->user()->shop_id]);
    }

    public function aCteATableFunctionAndALiteral()
    {
        return DB::select( // expect: trusted tables=invoices
            "WITH recent AS (SELECT id, status FROM invoices WHERE shop_id = ?)
             SELECT r.id, g.n FROM recent r CROSS JOIN generate_series(1, 3) AS g(n)
             WHERE r.status IS DISTINCT FROM 'void' AND r.status <> 'copied from customers'",
            [auth()->user()->shop_id]);
    }

    public function anUpdateWithAFromList()
    {
        return DB::update( // expect: review tables=items,karigars
            'UPDATE items SET karigar_name = k.name FROM karigars k WHERE k.id = items.karigar_id AND items.shop_id = ?',
            [auth()->user()->shop_id]);
    }

    public function aDynamicTable(string $table)
    {
        return DB::select("SELECT count(*) FROM {$table} WHERE shop_id = ?", [auth()->user()->shop_id]); // expect: review tables=?
    }

    public function sqlFromAVariable(string $sql)
    {
        return DB::select($sql); // expect: review tables=?
    }

    public function sqlAssignedOnceToAVariable(?int $shopId)
    {
        $sql = 'SELECT cn.invoice_id FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id'.($shopId ? " WHERE cn.shop_id = {$shopId}" : '');

        return DB::select($sql); // expect: review tables=credit_notes,invoices
    }

    public function sqlAppendedToAVariable()
    {
        $sql = 'SELECT id FROM invoices';
        $sql .= ' JOIN customers ON true';

        return DB::select($sql); // expect: review tables=?
    }

    public function aTableNotInTheSchema()
    {
        return DB::table('no_such_table')->count(); // expect: review tables=no_such_table
    }

    public function aKnownGlobalTable()
    {
        return DB::select('SELECT value FROM platform_settings WHERE key = ?', ['gst_rate']); // expect: global tables=platform_settings
    }

    public function noTableAtAll()
    {
        return DB::select('SELECT pg_advisory_xact_lock(?)', [42]); // expect: global tables=-
    }

    public function aTableOwnedByConvention(Request $request)
    {
        return DB::table('sessions')->where('user_id', $request->integer('user_id'))->delete(); // expect: review tables=sessions
    }

    // ── the tenant registry itself: a shop row is owned by its own id ────────
    public function everyShop()
    {
        return Shop::query()->where('is_active', true)->get(); // expect: review tables=shops
    }

    public function theCallersShopControl()
    {
        return Shop::find(auth()->user()->shop_id); // expect: trusted tables=shops
    }
}
