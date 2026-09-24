<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only. Every stored reference from one shop's row to a row of another
 * shop — the S3-14 / S3-15 class, for every reference the schema knows of.
 *
 * A reference is any declared foreign key between two tables that both carry
 * shop_id (whatever the column is called: customer_id, created_by, …), or a
 * `<name>_id` column with no declared key whose name matches such a table
 * (`invoice_id` → invoices). A row counts when both shop_ids
 * are set and differ; a NULL shop_id on the referenced row (a platform-wide
 * row) does not count.
 *
 * Tables without shop_id that belong to a shop through a parent (invoice_items
 * through invoices) are checked the same way: each of their other references
 * must name the parent's shop.
 *
 * Not covered, and listed: polymorphic references (`reference_id` with a
 * `reference_type`) and other `*_id` columns that name no shop table.
 *
 * Runs in a READ ONLY transaction with a statement timeout when started
 * outside a transaction; only SELECTs. Exits 1 when any cross-shop reference
 * exists. It shows where references cross shops, not whether anyone read
 * through them.
 */
class AuditForeignReferences extends Command
{
    protected $signature = 'tenant:audit-foreign-references
        {--table= : Only references from this table}
        {--examples=3 : Example row ids per reference}';

    protected $description = 'Read-only: count rows that reference another shop\'s row, for every reference between shop-owned tables.';

    public function handle(): int
    {
        $guarded = DB::transactionLevel() === 0;
        DB::beginTransaction();
        try {
            if ($guarded) {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            DB::statement("SET LOCAL statement_timeout = '120s'");

            return $this->audit($guarded);
        } finally {
            DB::rollBack();
        }
    }

    private function audit(bool $guarded): int
    {
        $shopTables = collect(DB::select("select table_name from information_schema.columns
            where table_schema = current_schema() and column_name = 'shop_id'"))->pluck('table_name')->flip();
        $declared = collect(DB::select("select kcu.table_name t, kcu.column_name c, ccu.table_name p
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name and kcu.table_schema = tc.table_schema
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema() and ccu.column_name = 'id'"));
        $idColumns = collect(DB::select("select table_name t, column_name c from information_schema.columns
            where table_schema = current_schema() and column_name like '%\\_id' and column_name <> 'shop_id'"));

        // Every declared key between shop tables, whatever the column is called
        // (created_by, finalized_by, …), then *_id columns with no declared key.
        $refs = $declared->filter(fn ($d) => isset($shopTables[$d->t], $shopTables[$d->p]) && $d->c !== 'shop_id')
            ->unique(fn ($d) => "{$d->t}.{$d->c}")->map(fn ($d) => [$d->t, $d->c, $d->p, 'declared'])->values()->all();
        $uncovered = [];
        foreach ($idColumns as $col) {
            if (! isset($shopTables[$col->t]) || $declared->contains(fn ($d) => $d->t === $col->t && $d->c === $col->c)) {
                continue;
            }
            $parent = collect([substr($col->c, 0, -3).'s', substr($col->c, 0, -3).'es'])->first(fn ($t) => isset($shopTables[$t]));
            if ($parent === null) {
                $uncovered[] = "{$col->t}.{$col->c}";
            } else {
                $refs[] = [$col->t, $col->c, $parent, 'implied'];
            }
        }
        // Tables without shop_id that reference two or more shop tables
        // (invoice_items: invoice_id and item_id): every reference a row holds
        // must name the same shop. The first column (by name) is compared with
        // each of the others; the check is symmetric.
        foreach ($declared->filter(fn ($d) => ! isset($shopTables[$d->t]) && isset($shopTables[$d->p]))->unique(fn ($d) => "{$d->t}.{$d->c}")
            ->sortBy('c')->groupBy('t') as $t => $keys) {
            $first = $keys->first();
            foreach ($keys->skip(1) as $d) {
                $refs[] = [$t, $d->c, $d->p, 'through '.$first->p.'.'.$first->c];
            }
        }
        if ($this->option('table')) {
            $refs = array_values(array_filter($refs, fn ($r) => $r[0] === $this->option('table')));
        }

        $crossing = 0;
        $total = 0;
        foreach ($refs as [$t, $c, $p, $how]) {
            $through = str_starts_with($how, 'through ');
            if ($through) {
                [$ownerTable, $ownerColumn] = explode('.', substr($how, 8), 2);
                $from = "from \"{$t}\" x join \"{$ownerTable}\" a on a.id = x.\"{$ownerColumn}\" join \"{$p}\" b on b.id = x.\"{$c}\" "
                    .'where a.shop_id is not null and b.shop_id is not null and a.shop_id <> b.shop_id';
                // The row's own identity is x.id — never the referenced parent's.
                $select = 'x.id as id, a.id as parent_id, a.shop_id as parent_shop_id, b.id as ref_id, b.shop_id as ref_shop_id';
            } else {
                $from = "from \"{$t}\" a join \"{$p}\" b on b.id = a.\"{$c}\" where a.shop_id is not null and b.shop_id is not null and a.shop_id <> b.shop_id";
                $select = 'a.id as id, a.shop_id, b.id as ref_id, b.shop_id as ref_shop_id';
            }
            $n = (int) DB::selectOne("select count(*) as n {$from}")->n;
            if ($n === 0) {
                continue;
            }
            $crossing++;
            $total += $n;
            $examples = collect(DB::select("select {$select} {$from} order by 1 limit ?", [max(1, (int) $this->option('examples'))]))
                ->map(fn ($r) => $through
                    ? "{$t} {$r->id}: {$ownerColumn} -> {$ownerTable} {$r->parent_id} (shop {$r->parent_shop_id}), {$c} -> {$p} {$r->ref_id} (shop {$r->ref_shop_id})"
                    : "{$t} {$r->id} (shop {$r->shop_id}) -> {$p} {$r->ref_id} (shop {$r->ref_shop_id})")->implode('; ');
            $this->warn("{$t}.{$c} -> {$p} ({$how}): {$n} row(s) reference another shop — e.g. {$examples}");
        }

        $this->line(sprintf('references checked: %d (%d declared, %d implied by name, %d through a parent); crossing shops: %d, rows: %d',
            count($refs), count(array_filter($refs, fn ($r) => $r[3] === 'declared')), count(array_filter($refs, fn ($r) => $r[3] === 'implied')),
            count(array_filter($refs, fn ($r) => str_starts_with($r[3], 'through'))), $crossing, $total));
        $this->line('not covered (polymorphic or naming no shop table): '.(implode(', ', $uncovered) ?: 'none'));
        $this->line($guarded ? 'ran in a READ ONLY transaction' : 'ran inside the caller\'s transaction (READ ONLY could not be set); SELECTs only');

        return $crossing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
