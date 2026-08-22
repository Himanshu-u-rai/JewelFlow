<?php

namespace Tests\Feature;

use App\Models\EntityEvent;
use App\Services\EntityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class EntityEventSnapshotTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_snapshot_round_trips_as_an_array(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        $event = app(EntityEventService::class)->record(
            shopId:     $shop->id,
            entityType: 'return_order',
            entityId:   1,
            eventType:  'return_settled',
            summary:    'Return settled',
            detail:     ['refund_total' => 1500.75],
            snapshot:   ['return_order_id' => 1, 'credit_note_number' => 'CN-1'],
        );

        $this->assertNotNull($event, 'record() returned null — the audit write failed.');

        $stored = EntityEvent::withoutTenant()->findOrFail($event->id);

        $this->assertSame(['return_order_id' => 1, 'credit_note_number' => 'CN-1'], $stored->snapshot);
        $this->assertSame(['refund_total' => 1500.75], $stored->detail);
    }

    /**
     * A broken audit write must be loud in CI. Silently logging it is how a
     * missing `snapshot` cast dropped every return, sale and job order event.
     */
    public function test_a_failed_audit_write_throws_in_the_test_environment(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        app(EntityEventService::class)->record(
            shopId:     999999,   // no such shop — FK violation
            entityType: 'return_order',
            entityId:   1,
            eventType:  'return_settled',
            summary:    'Return settled',
        );
    }

    /**
     * In production a broken audit write is swallowed so the sale survives.
     * On Postgres a try/catch alone does NOT achieve that: the failed statement
     * poisons the surrounding transaction and every later statement, COMMIT
     * included, is refused. The audit write must therefore run inside its own
     * savepoint. Observers always fire inside the service's DB::transaction()
     * (ReturnService, ExchangeService, CreditNoteService), so this is the real
     * shape of the failure, not a contrived one.
     */
    public function test_a_swallowed_audit_failure_leaves_the_business_transaction_usable(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        $this->app->detectEnvironment(fn () => 'production');

        try {
            DB::transaction(function () use ($shop) {
                DB::table('shop_counters')->insert([
                    'shop_id'       => $shop->id,
                    'counter_key'   => 'audit_savepoint_probe',
                    'current_value' => 7,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                // Fails on the shop_id FK. Must be contained, not fatal.
                $this->assertNull(app(EntityEventService::class)->record(
                    shopId:     999999,
                    entityType: 'return_order',
                    entityId:   1,
                    eventType:  'return_settled',
                    summary:    'Return settled',
                ));
            });
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertDatabaseHas('shop_counters', [
            'shop_id'     => $shop->id,
            'counter_key' => 'audit_savepoint_probe',
        ]);
    }

    /**
     * Same guarantee for the idempotency probe. Observers call it immediately
     * before record(), inside the same transaction, so an unguarded read there
     * is just as fatal as an unguarded write.
     */
    public function test_a_failing_idempotency_probe_does_not_break_the_caller(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            Schema::rename('entity_events', 'entity_events_hidden');

            $this->assertFalse(app(EntityEventService::class)->alreadyRecorded(
                shopId:     1,
                entityType: 'return_order',
                entityId:   1,
                eventType:  'return_settled',
                occurredAt: now(),
            ));
        } finally {
            Schema::rename('entity_events_hidden', 'entity_events');
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(0, EntityEvent::withoutTenant()->count());
    }
}
