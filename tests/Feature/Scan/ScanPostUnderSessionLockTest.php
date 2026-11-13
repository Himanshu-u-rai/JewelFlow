<?php

namespace Tests\Feature\Scan;

use App\Models\ScanEvent;
use App\Models\ScanSession;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N7/N13 (2026-11-13): a phone scan was checked against the session as the request first read it, then
 * written. A session ended meanwhile still took the scan (never picked up), and the 2-second duplicate filter
 * locked "recent matching scans", which locks nothing when none exists yet, so two simultaneous scans of one tag
 * both went in. The app's endpoint had no filter at all. Both now record through ScanSession::recordScan(), under
 * the session's row lock.
 */
class ScanPostUnderSessionLockTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function scanSession(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'TAG-1', 'status' => 'in_stock']);
        $token = $this->actingAs($user)->postJson('/scan-session/create')->assertOk()->json('token');

        return [$user, $shop, ScanSession::where('token', $token)->firstOrFail()];
    }

    private function endedOnceLoaded(ScanSession $session): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: ' . ScanSession::class, function (ScanSession $loaded) use ($session, &$done) {
            if ($done || $loaded->id !== $session->id) {
                return;
            }
            $done = true;
            DB::table('scan_sessions')->where('id', $session->id)->update(['status' => 'expired']);
        });
    }

    public function test_a_scan_into_a_session_ended_meanwhile_is_refused(): void
    {
        [, , $session] = $this->scanSession();
        $this->endedOnceLoaded($session);

        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'TAG-1'])->assertStatus(410);
        $this->assertSame(0, ScanEvent::where('scan_session_id', $session->id)->count());
    }

    public function test_the_app_filters_a_repeat_scan_like_the_web(): void
    {
        [$user, , $session] = $this->scanSession();
        $bearer = $user->createToken('scanner')->plainTextToken;
        foreach ([1, 2] as $_) {
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['Authorization' => 'Bearer ' . $bearer, 'X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->postJson('/api/mobile/scan/send',['token' => $session->token, 'barcode' => 'TAG-1'])->assertSuccessful();
        }

        $this->assertSame(1, ScanEvent::where('scan_session_id', $session->id)->count(), 'a double tap is one scan');
    }

    /**
     * Security review of 63892429: a filtered repeat answered "queued" and audited "barcode sent" with the earlier
     * scan's id, so the trail showed a second send that never created anything. It now says what happened, in the
     * name of whoever sent it, and points at the scan that does exist (the app tracks delivery by that id).
     */
    public function test_a_filtered_repeat_is_reported_and_audited_as_filtered_in_the_senders_name(): void
    {
        [$first, $shop, $session] = $this->scanSession();
        $second = $this->createOwnerUser($shop, $first->role);
        $send = function ($user) use ($session) {
            $this->app['auth']->forgetGuards();

            return $this->withHeaders([
                'Authorization' => 'Bearer ' . $user->createToken('scanner')->plainTextToken,
                'X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
            ])->postJson('/api/mobile/scan/send', ['token' => $session->token, 'barcode' => 'TAG-1'])->assertOk();
        };

        $sent = $send($first);
        $repeat = $send($second);

        $this->assertSame('queued', $sent->json('status'));
        $this->assertSame('debounced', $repeat->json('status'));
        $this->assertSame($sent->json('event_id'), $repeat->json('event_id'), 'the app keeps tracking the real scan');

        $audit = DB::table('audit_logs')->where('shop_id', $shop->id)->where('action', 'like', 'mobile_scan_barcode_%')
            ->orderBy('id')->get(['user_id', 'action']);
        $this->assertSame([
            [(int) $first->id, 'mobile_scan_barcode_sent'],
            [(int) $second->id, 'mobile_scan_barcode_debounced'],
        ], $audit->map(fn ($row) => [(int) $row->user_id, $row->action])->all());
    }

    public function test_one_way_to_record_a_scan(): void
    {
        foreach (['app/Http/Controllers/ScanSessionController.php', 'app/Http/Controllers/Api/Mobile/ScanController.php'] as $file) {
            $this->assertStringNotContainsString('ScanEvent::create(', file_get_contents(base_path($file)), $file);
            $this->assertStringContainsString('ScanSession::recordScan(', file_get_contents(base_path($file)), $file);
        }
    }
}
