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
        $request->validate([
            'token' => 'required|string|size:48',
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

        // Mark mobile as connected (first time only)
        if (!$session->mobile_connected_at) {
            $session->update(['mobile_connected_at' => now()]);
        }

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
            'session_id' => $session->id,
            'status' => $session->status,
            'expires_at' => $session->expires_at->toIso8601String(),
            'message' => 'Connected to POS scan session.',
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

        $event = ScanEvent::create([
            'scan_session_id' => $session->id,
            'barcode' => $request->barcode,
            'processed' => false,
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

        return response()->json([
            'event_id' => $event->id,
            'barcode' => $event->barcode,
            'message' => 'Barcode sent to POS.',
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
