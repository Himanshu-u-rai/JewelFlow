<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * EnsureIdempotency
 *
 * Wraps mutation routes under /api/mobile/v1/ so retries are safe. Backed by
 * the `idempotency_keys` table (NOT Redis — see M3 plan).
 *
 * Contract:
 *   - GET/HEAD/OPTIONS: pass through.
 *   - POST/PATCH/PUT/DELETE: require `X-Idempotency-Key` header OR an
 *     `idempotency_key` field in the JSON body. Missing → 422.
 *   - Key shape: 8–80 chars, [A-Za-z0-9_-]. Invalid → 422.
 *   - Replay (same key + same payload hash): return cached response with
 *     `X-Idempotent-Replay: true` header. Controller is NOT invoked.
 *   - Conflict (same key + DIFFERENT payload hash): 409. This catches the bug
 *     where a client retries with a stale key against a now-mutated payload.
 *   - DB outage: fail-closed on reads (503), fail-soft on writes (log + pass).
 *
 * Scoped to (shop_id, user_id, key). Two users can use the same key safely;
 * two shops can use the same key safely.
 */
class EnsureIdempotency
{
    /**
     * HTTP methods that require an idempotency key.
     *
     * GET/HEAD/OPTIONS are safe per RFC 9110 and pass through.
     */
    private const MUTATION_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    /**
     * Key shape: 8–80 chars, URL-safe characters only. UUIDs (36 chars) fit;
     * ULIDs (26 chars) fit; opaque tokens up to 80 chars fit.
     */
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,80}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());

        if (! in_array($method, self::MUTATION_METHODS, true)) {
            return $next($request);
        }

        // Extract key: prefer header, fall back to body field (legacy PosController
        // convention — some endpoints accept `idempotency_key` in the body).
        $key = $request->header('X-Idempotency-Key');
        if (! is_string($key) || $key === '') {
            $bodyKey = $request->input('idempotency_key');
            $key = is_string($bodyKey) ? $bodyKey : null;
        }

        if (! is_string($key) || $key === '') {
            return $this->errorResponse(
                422,
                'missing_idempotency_key',
                'X-Idempotency-Key header is required for this mutation.',
            );
        }

        if (! preg_match(self::KEY_PATTERN, $key)) {
            return $this->errorResponse(
                422,
                'invalid_idempotency_key',
                'X-Idempotency-Key must be 8-80 characters, letters/digits/_/- only.',
            );
        }

        $user = $request->user();
        if (! $user) {
            // The auth:sanctum middleware should have rejected this already; if
            // somehow we got here without a user, we can't scope the key.
            return $this->errorResponse(
                401,
                'unauthenticated',
                'Authentication is required for idempotent mutations.',
            );
        }

        $shopId = (int) ($user->shop_id ?? 0);
        $userId = (int) $user->id;

        $requestHash = hash(
            'sha256',
            $method . '|' . $request->path() . '|' . $request->getContent(),
        );

        // ─── Lookup existing key ───────────────────────────────────────────
        try {
            $existing = IdempotencyKey::query()
                ->where('shop_id', $shopId)
                ->where('user_id', $userId)
                ->where('key', $key)
                ->first();
        } catch (Throwable $e) {
            // DB unavailable on read → fail-closed. Better to make the client
            // retry than to risk double-charging because we couldn't check.
            Log::error('EnsureIdempotency: database read failed', [
                'error' => $e->getMessage(),
                'shop_id' => $shopId,
                'user_id' => $userId,
            ]);
            return $this->errorResponse(
                503,
                'idempotency_unavailable',
                'Idempotency check is temporarily unavailable. Please retry.',
            );
        }

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return $this->errorResponse(
                    409,
                    'idempotency_key_conflict',
                    'This idempotency key was already used with a different request payload.',
                );
            }

            // Replay: return cached response, controller is not invoked.
            $body = $existing->response_body;
            $status = $existing->response_status;
            $response = response()->json($body, $status);
            $response->headers->set('X-Idempotent-Replay', 'true');
            return $response;
        }

        // ─── First time seeing this key: run the controller ───────────────
        $response = $next($request);

        // Only persist successful responses (2xx). Errors should be retryable
        // with the same key — there's nothing to replay for a 4xx/5xx.
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            try {
                $decoded = json_decode($response->getContent(), true);

                IdempotencyKey::create([
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => $status,
                    'response_body' => is_array($decoded) ? $decoded : null,
                ]);
            } catch (QueryException $e) {
                // Unique constraint race: a sibling request beat us to the insert.
                // The original response is still fine to return — the cached copy
                // (from the sibling) will serve future replays. Log for visibility.
                Log::info('EnsureIdempotency: concurrent insert collided on unique key', [
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                ]);
            } catch (Throwable $e) {
                // Write failure: fail-soft. The request already succeeded; the
                // client will see the response. A future retry with the same key
                // will simply re-run (not ideal, but better than failing a
                // successful mutation).
                Log::warning('EnsureIdempotency: failed to persist key', [
                    'error' => $e->getMessage(),
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                ]);
            }
        }

        return $response;
    }

    private function errorResponse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [
                [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
        ], $status);
    }
}
