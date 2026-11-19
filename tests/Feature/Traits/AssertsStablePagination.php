<?php

namespace Tests\Feature\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Lists ordered by a column that can tie (created_at, a date, a name) must end
 * on a unique column, or PostgreSQL may return the tied rows in a different
 * order for each LIMIT/OFFSET query: rows then repeat across pages or drop out.
 * See CustomerListPaginationTest for the original case.
 */
trait AssertsStablePagination
{
    /**
     * Give every row the same sort values, then rewrite the rows in a shuffled
     * order so their physical order no longer follows the id, as everyday edits
     * do. Without a tie-breaker the pages then come back scrambled.
     *
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $tiedValues  e.g. ['created_at' => '2026-11-01 10:00:00']
     * @param  string  $touchColumn  a column safe to rewrite with its own value
     */
    protected function tieAndScramble(string $table, array $ids, array $tiedValues, string $touchColumn = 'updated_at'): void
    {
        DB::table($table)->whereIn('id', $ids)->update($tiedValues);

        $shuffled = $ids;
        mt_srand(7);
        shuffle($shuffled);
        foreach ($shuffled as $id) {
            DB::table($table)->where('id', $id)->update([$touchColumn => DB::raw($touchColumn)]);
        }
    }

    /**
     * Walk every page and assert each id shows on exactly one of them.
     *
     * @param  array<int, int>  $ids
     * @param  callable(int): array<int, int>  $pageIds  returns the ids shown on page N
     */
    protected function assertEachOnExactlyOnePage(array $ids, callable $pageIds, int $perPage, string $list = 'list'): void
    {
        $seen = [];
        $pages = (int) ceil(count($ids) / $perPage);
        for ($p = 1; $p <= $pages; $p++) {
            $onPage = $pageIds($p);
            $this->assertCount(min($perPage, count($ids) - ($p - 1) * $perPage), $onPage, "{$list}: page {$p} size");
            $seen = array_merge($seen, $onPage);
        }

        $this->assertSame(count($seen), count(array_unique($seen)), "{$list}: no row on two pages");
        $this->assertEqualsCanonicalizing($ids, $seen, "{$list}: every row on exactly one page");
    }
}
