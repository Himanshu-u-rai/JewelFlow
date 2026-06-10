<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ScanEvent;
use App\Models\ScanSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ScanController extends Controller
{
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:48',
            'device_install_id' => 'nullable|string|max:64',
        ]);

        $session = ScanSession::where('token', $request->token)
            ->where('shop_id', $request->user()->shop_id)
            ->active()
            ->first();

        if (! $session) {
            $this->logAuditSafely(
                $request,
                action: 'mobile_scan_connect_failed',
                modelType: 'ScanSession',
                modelId: 0,
                description: 'Mobile scanner failed to connect: invalid or expired session token.',
                data: [
                    'source' => 'mobile_app',
                    'token_prefix' => substr((string) $request->token, 0, 8),
                ],
            );

            return response()->json([
                'message' => 'Invalid or expired scan session.',
            ], 404);
        }

        // Record this device as a connected scanner. Multiple scanners may feed
        // one session; this column tracks the most recent connector for display.
        // Per-scan ownership is recorded on each scan_event (posted_by_user_id).
        $session->forceFill([
            'mobile_connected_at' => $session->mobile_connected_at ?? now(),
            'connected_user_id'   => (int) $request->user()->id,
            'device_install_id'   => $validated['device_install_id'] ?? $session->device_install_id,
        ])->save();

        $this->logAuditSafely(
            $request,
            action: 'mobile_scan_connected',
            modelType: 'ScanSession',
            modelId: (int) $session->id,
            description: 'Mobile scanner connected to POS session.',
            data: [
                'source' => 'mobile_app',
                'session_id' => $session->id,
                'token_prefix' => substr($session->token, 0, 8),
            ],
        );

        return response()->json([
            'session_id'   => $session->id,
            'status'       => $session->status,
            'expires_at'   => $session->expires_at->toIso8601String(),
            'pos_listening' => $session->isPosListening(),
            'message'      => 'Connected to POS scan session.',
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|size:48',
            'barcode' => 'required|string|max:100',
        ]);

        $session = ScanSession::where('token', $request->token)
            ->where('shop_id', $request->user()->shop_id)
            ->active()
            ->first();

        if (! $session) {
            $this->logAuditSafely(
                $request,
                action: 'mobile_scan_send_failed',
                modelType: 'ScanSession',
                modelId: 0,
                description: 'Mobile scanner failed to send barcode: invalid or expired session token.',
                data: [
                    'source' => 'mobile_app',
                    'token_prefix' => substr((string) $request->token, 0, 8),
                    'barcode' => (string) $request->barcode,
                ],
            );

            return response()->json([
                'message' => 'Invalid or expired scan session.',
            ], 404);
        }

        // Attribute every scan to the sending user + token (multi-scanner truth).
        $event = ScanEvent::create([
            'scan_session_id'    => $session->id,
            'barcode'            => $request->barcode,
            'processed'          => false,
            'posted_by_user_id'  => (int) $request->user()->id,
            'posted_by_token_id' => $request->user()->currentAccessToken()?->id,
        ]);

        $this->logAuditSafely(
            $request,
            action: 'mobile_scan_barcode_sent',
            modelType: 'ScanEvent',
            modelId: (int) $event->id,
            description: 'Mobile scanner sent barcode to POS session.',
            data: [
                'source' => 'mobile_app',
                'session_id' => $session->id,
                'barcode' => $event->barcode,
                'scan_event_id' => $event->id,
            ],
        );

        // Delivery confirmation contract: a scan is only QUEUED here. The client
        // must call /scan/status to learn when it is actually consumed by the
        // POS (processed=true => "added to cart"). pos_listening tells the
        // scanner up-front whether anyone is currently polling.
        return response()->json([
            'event_id'      => $event->id,
            'barcode'       => $event->barcode,
            'status'        => 'queued',
            'pos_listening' => $session->isPosListening(),
            'message'       => 'Barcode queued for POS.',
        ]);
    }

    /**
     * Delivery-confirmation poll (Option B). The scanner reports the event ids
     * it is tracking; we return which are now processed (= reached the cart)
     * and whether the POS is actively listening. No writes — pure read.
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'      => 'required|string|size:48',
            'event_ids'  => 'nullable|array|max:100',
            'event_ids.*' => 'integer',
        ]);

        $session = ScanSession::where('token', $validated['token'])
            ->where('shop_id', $request->user()->shop_id)
            ->active()
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Invalid or expired scan session.',
            ], 404);
        }

        $events = [];
        if (! empty($validated['event_ids'])) {
            $events = ScanEvent::where('scan_session_id', $session->id)
                ->whereIn('id', $validated['event_ids'])
                ->get(['id', 'processed'])
                ->map(fn ($e) => [
                    'event_id'  => (int) $e->id,
                    'processed' => (bool) $e->processed,
                ])
                ->values();
        }

        return response()->json([
            'pos_listening' => $session->isPosListening(),
            'expires_at'    => $session->expires_at->toIso8601String(),
            'events'        => $events,
        ]);
    }

    private function logAuditSafely(
        Request $request,
        string $action,
        string $modelType,
        int $modelId,
        string $description,
        array $data = [],
    ): void {
        try {
            AuditLog::create([
                'shop_id' => (int) $request->user()->shop_id,
                'user_id' => (int) $request->user()->id,
                'action' => $action,
                'model_type' => $modelType,
                'model_id' => $modelId,
                'description' => $description,
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
