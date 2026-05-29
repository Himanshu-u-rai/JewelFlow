<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\PendingUpload;
use App\Services\Mobile\UploadIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Upload lifecycle (M6).
 *
 * Three-step flow:
 *
 *   POST   /api/mobile/v1/uploads/intent     — mint an upload intent
 *   PUT    /api/mobile/v1/uploads/{token}    — stream bytes to the server
 *   GET    /api/mobile/v1/uploads/{token}    — poll upload status
 *
 * The PUT endpoint intentionally bypasses the mobile.idempotency middleware
 * (the upload_token already IS the idempotency key for the bytes stream).
 * It also intentionally bypasses the mobile.envelope middleware at the route
 * level because it may receive a large binary body that must not be buffered
 * twice — see routes/mobile_v1.php for the excluded group.
 *
 * Drift note: this controller handles only media lifecycle. It never reads
 * pricing rates, metal identities, or vault data.
 */
class UploadController extends Controller
{
    public function __construct(private UploadIntentService $intents) {}

    /**
     * POST /api/mobile/v1/uploads/intent
     *
     * Body: { kind, content_type, size_bytes }
     * Returns: { upload_id, upload_url, expires_at, max_size_bytes, thumbnail_url_when_ready }
     */
    public function intent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind'         => 'required|string|in:' . implode(',', UploadIntentService::ALLOWED_KINDS),
            'content_type' => 'required|string|in:' . implode(',', array_keys(UploadIntentService::ALLOWED_CONTENT_TYPES)),
            'size_bytes'   => 'required|integer|min:1|max:' . UploadIntentService::MAX_SIZE_BYTES,
        ]);

        try {
            $upload = $this->intents->mintIntent(
                shopId:      (int) $request->user()->shop_id,
                userId:      (int) $request->user()->id,
                kind:        $validated['kind'],
                contentType: $validated['content_type'],
                sizeBytes:   (int) $validated['size_bytes'],
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'errors' => [['code' => 'upload_intent_invalid', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'upload_id'              => $upload->upload_token,
            'upload_url'             => url("/api/mobile/v1/uploads/{$upload->upload_token}"),
            'expires_at'             => $upload->expires_at->toIso8601String(),
            'max_size_bytes'         => UploadIntentService::MAX_SIZE_BYTES,
            'status'                 => $upload->status,
            'thumbnail_url_when_ready' => null,
        ], 201);
    }

    /**
     * PUT /api/mobile/v1/uploads/{token}
     *
     * Raw bytes in request body. Content-Type must match the intent's
     * declared type. This endpoint is NOT wrapped by mobile.envelope —
     * see routes/mobile_v1.php.
     */
    public function upload(Request $request, string $token): JsonResponse
    {
        $upload = PendingUpload::where('upload_token', $token)
            ->where('shop_id', $request->user()->shop_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $upload) {
            return response()->json([
                'errors' => [['code' => 'upload_not_found', 'message' => 'Upload token not found or does not belong to you.']],
            ], 404);
        }

        if ($upload->isExpired() || $upload->status === 'expired') {
            return response()->json([
                'errors' => [['code' => 'upload_expired', 'message' => 'This upload intent has expired. Request a new one.']],
            ], 410);
        }

        if (! in_array($upload->status, ['pending', 'uploaded'], true)) {
            return response()->json([
                'errors' => [['code' => 'upload_not_writable', 'message' => "Upload is in '{$upload->status}' state and cannot receive bytes."]],
            ], 409);
        }

        $rawBytes = $request->getContent();

        if (strlen($rawBytes) === 0) {
            return response()->json([
                'errors' => [['code' => 'empty_body', 'message' => 'Request body is empty.']],
            ], 422);
        }

        try {
            $upload = $this->intents->store($upload, $rawBytes);
        } catch (\RuntimeException $e) {
            return response()->json([
                'errors' => [['code' => 'upload_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json($this->present($upload));
    }

    /**
     * GET /api/mobile/v1/uploads/{token}
     *
     * Returns current upload status. Useful for polling after PUT.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $upload = PendingUpload::where('upload_token', $token)
            ->where('shop_id', $request->user()->shop_id)
            ->first();

        if (! $upload) {
            return response()->json([
                'errors' => [['code' => 'upload_not_found', 'message' => 'Upload token not found.']],
            ], 404);
        }

        return response()->json($this->present($upload));
    }

    private function present(PendingUpload $upload): array
    {
        return [
            'upload_id'       => $upload->upload_token,
            'kind'            => $upload->kind,
            'status'          => $upload->status,
            'expires_at'      => optional($upload->expires_at)->toIso8601String(),
            'consumed_at'     => optional($upload->consumed_at)->toIso8601String(),
            'original_url'    => $this->intents->originalUrl($upload),
            'thumbnail_url'   => $this->intents->thumbnailUrl($upload),
            'actual_size_bytes' => $upload->actual_size_bytes,
        ];
    }
}
