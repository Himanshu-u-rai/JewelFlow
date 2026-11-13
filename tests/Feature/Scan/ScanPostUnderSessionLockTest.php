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

    public function test_one_way_to_record_a_scan(): void
    {
        foreach (['app/Http/Controllers/ScanSessionController.php', 'app/Http/Controllers/Api/Mobile/ScanController.php'] as $file) {
            $this->assertStringNotContainsString('ScanEvent::create(', file_get_contents(base_path($file)), $file);
            $this->assertStringContainsString('ScanSession::recordScan(', file_get_contents(base_path($file)), $file);
        }
    }
}
