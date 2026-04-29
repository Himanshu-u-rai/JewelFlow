<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ScanEvent;
use App\Models\ScanSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ScanSessionController extends Controller
{
    private const SESSION_MINUTES = 480; // 8 hours — full work shift
    private const RENEW_THRESHOLD_MINUTES = 30; // renew when less than 30 min left

    /**
     * Create a scan session for this shop.
     * Called by POS via AJAX. QR code is generated client-side.
     */
    public function create(Request $request)
    {
        $user   = Auth::user();
        $shopId = $user->shop_id;

        if (!$shopId) {
            return response()->json(['error' => 'No shop associated with your account.'], 422);
        }

        $token     = Str::random(48);
        $expiresAt = now()->addMinutes(self::SESSION_MINUTES);

        $session = ScanSession::create([
            'shop_id'    => $shopId,
            'token'      => $token,
            'status'     => 'active',
            'expires_at' => $expiresAt,
        ]);

        AuditLog::create([
            'shop_id' => $shopId,
            'user_id' => (int) $user->id,
            'action' => 'scan_session_created',
            'model_type' => 'ScanSession',
            'model_id' => (int) $session->id,
            'description' => 'POS mobile scan session started.',
            'data' => [
                'source' => 'web_pos',
                'session_id' => $session->id,
                'expires_at' => $expiresAt->toIso8601String(),
                'token_prefix' => substr($token, 0, 8),
            ],
        ]);

        // Build signed, relative URL and prefix with current host so the phone can
        // reach the correct LAN address while signature remains valid.
        $scanPath = URL::temporarySignedRoute('scan.mobile', $expiresAt, ['token' => $token], false);
        $baseUrl = $request->getSchemeAndHttpHost();
        $scanUrl = $baseUrl . $scanPath;

        return response()->json([
            'token'      => $token,
            'scan_url'   => $scanUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => self::SESSION_MINUTES * 60,
        ]);
    }

    /**
     * Manually expire a scan session.
     */
    public function expire(Request $request, string $token)
    {
        $shopId = Auth::user()->shop_id;

        $session = ScanSession::where('token', $token)
            ->where('shop_id', $shopId)
            ->first();

        $updated = 0;
        if ($session) {
            $updated = ScanSession::where('id', $session->id)->update(['status' => 'expired']);
        }

        if ($updated > 0) {
            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => (int) Auth::id(),
                'action' => 'scan_session_expired',
                'model_type' => 'ScanSession',
                'model_id' => (int) $session->id,
                'description' => 'POS mobile scan session manually expired.',
                'data' => [
                    'source' => 'web_pos',
                    'token_prefix' => substr($token, 0, 8),
                ],
            ]);
        }

        return response()->json(['status' => 'expired']);
    }

    /**
     * Desktop POS polls this every 1.5s to get new barcode scans.
     */
    public function poll(Request $request, string $token)
    {
        $shopId = Auth::user()->shop_id;

        $session = ScanSession::where('token', $token)
            ->where('shop_id', $shopId)
            ->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        if (!$session->isActive()) {
            return response()->json(['status' => 'expired', 'barcodes' => []]);
        }

        // Auto-renew session if expiring soon (less than 30 min left)
        $this->renewIfExpiringSoon($session);

        // Atomically claim unprocessed events so concurrent polls don't deliver
        // the same barcode twice (two browser tabs on the same POS).
        $events = DB::transaction(function () use ($session) {
            $rows = ScanEvent::where('scan_session_id', $session->id)
                ->whereRaw('NOT processed')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                ScanEvent::whereIn('id', $rows->pluck('id'))
                    ->update(['processed' => DB::raw('true')]);
            }

            return $rows;
        });

        if ($events->isNotEmpty()) {
            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => (int) Auth::id(),
                'action' => 'scan_events_consumed',
                'model_type' => 'ScanSession',
                'model_id' => (int) $session->id,
                'description' => 'POS consumed mobile scan event(s).',
                'data' => [
                    'source' => 'mobile_scanner',
                    'session_id' => $session->id,
                    'event_count' => $events->count(),
                    'event_ids' => $events->pluck('id')->values()->all(),
                    'barcodes' => $events->pluck('barcode')->values()->all(),
                ],
            ]);
        }

        $session->refresh();

        return response()->json([
            'status'           => 'active',
            'barcodes'         => $events->pluck('barcode')->values(),
            'expires_at'       => $session->expires_at->toIso8601String(),
            'mobile_connected' => (bool) $session->mobile_connected_at,
        ]);
    }

    /**
     * Silently extend session if it has less than RENEW_THRESHOLD_MINUTES left.
     * Called on every poll — owner never sees an interruption.
     */
    private function renewIfExpiringSoon(ScanSession $session): void
    {
        $minutesLeft = now()->diffInMinutes($session->expires_at, false);
        if ($minutesLeft <= self::RENEW_THRESHOLD_MINUTES) {
            $session->update([
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES),
            ]);
        }
    }

    /**
     * PUBLIC: Mobile phone opens this page (no auth required).
     */
    public function mobile(string $token)
    {
        $session = ScanSession::where('token', $token)->first();

        if (!$session) {
            abort(404, 'Invalid scan session.');
        }

        $expired = !$session->isActive();

        // Mark mobile as connected (first time only)
        if (!$expired && !$session->mobile_connected_at) {
            $session->update(['mobile_connected_at' => now()]);
        }

        return view('scan.mobile', compact('session', 'token', 'expired'));
    }

    /**
     * PUBLIC: Mobile phone POSTs each scanned barcode here.
     */
    public function postScan(Request $request)
    {
        $request->validate([
            'token'   => 'required|string|size:48',
            'barcode' => 'required|string|max:255',
        ]);

        $session = ScanSession::where('token', $request->token)->first();

        if (!$session || !$session->isActive()) {
            return response()->json(['error' => 'Session expired or invalid.'], 410);
        }

        // Debounce: ignore same barcode scanned within last 2 seconds.
        // Wrapped in a transaction with a lock so two simultaneous POSTs for the
        // same barcode can't both pass the existence check.
        $debounced = false;
        DB::transaction(function () use ($session, $request, &$debounced) {
            $recent = ScanEvent::where('scan_session_id', $session->id)
                ->where('barcode', $request->barcode)
                ->where('created_at', '>=', now()->subSeconds(2))
                ->lockForUpdate()
                ->exists();

            if ($recent) {
                $debounced = true;
                return;
            }

            ScanEvent::create([
                'scan_session_id' => $session->id,
                'barcode'         => $request->barcode,
            ]);
        });

        if ($debounced) {
            return response()->json(['status' => 'debounced']);
        }

        return response()->json(['status' => 'ok']);
    }
}
