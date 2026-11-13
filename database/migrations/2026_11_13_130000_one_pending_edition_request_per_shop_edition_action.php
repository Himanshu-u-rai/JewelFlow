<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit N4 (2026-11-13): one pending edition request per shop, edition and action. "Already pending" was a
 * look-then-insert and a blocked remove filed a new request every time, so duplicates could queue up.
 *
 * Existing duplicates are support tickets, not money: the oldest pending one stays, the newer ones are closed as
 * cancelled with a note naming the request they duplicate, so the admin queue shows each ask once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE shop_edition_requests r
            SET status = 'cancelled',
                reviewed_at = now(),
                review_notes = 'Duplicate of pending request #' || keep.id || ' (closed when one pending request per service became a rule).'
            FROM (
                SELECT DISTINCT ON (shop_id, edition, action) id, shop_id, edition, action
                FROM shop_edition_requests
                WHERE status = 'pending'
                ORDER BY shop_id, edition, action, id
            ) keep
            WHERE r.status = 'pending'
              AND r.shop_id = keep.shop_id AND r.edition = keep.edition AND r.action = keep.action
              AND r.id <> keep.id
            SQL);

        DB::statement("CREATE UNIQUE INDEX shop_edition_requests_one_pending ON shop_edition_requests (shop_id, edition, action) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shop_edition_requests_one_pending');
    }
};
