<?php

namespace Tests\Feature;

use App\Models\EntityEvent;
use App\Services\EntityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
