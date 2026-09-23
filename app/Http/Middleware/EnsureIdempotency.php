<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * S3-09e. Headers a controller sets that a replay must carry: the entity
     * tag is the client's only source for its next `If-Match`. An allowlist on
     * purpose — a replayed `Set-Cookie` or rate-limit header would describe
     * the original request, not the resource.
     */
    private const REPLAYED_HEADERS = ['ETag', 'X-Has-Entity-Tag'];

    /**
     * Sentinel stored in `response_status` while the controller is running.
     *
     * S3-09. The claim is now staked BEFORE the controller so that a failure
     * in the middle of a mutation cannot be replayed as a fresh request, and
     * so that two concurrent same-key requests cannot both reach the
     * controller. That stake needs a value for a NOT NULL smallint column
     * before any response exists.
     *
     * 0 is used rather than making the column nullable, because widening a
     * column on a populated production table is a migration this repair does
     * not need: 0 is not a valid HTTP status, so it is unambiguous. If the
     * column is ever made nullable for other reasons, NULL and 0 should both
     * be treated as in-flight.
     */
    private const STATUS_IN_FLIGHT = 0;

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

            // S3-09. An in-flight claim means a previous request for this key
            // reached the controller and we never learned how it ended — it
            // either crashed mid-mutation, or a sibling request is executing
            // right now. Either way the only answer that cannot double-charge
            // is a refusal. See the class docblock for the reasoning.
            // Anything that is not a usable HTTP status is treated as
            // in-flight. That is the sentinel 0, a NULL if the column is ever
            // widened, and any corrupted value — deliberately a range check
            // rather than `=== 0`, because the one thing that must never
            // happen is the internal sentinel escaping as a real response
            // status. `response()->json($body, 0)` would throw inside the
            // middleware and surface as a 500 on a route whose whole job is
            // to answer safely, and a corrupted value would be worse: it
            // would tell the client something definite about an operation
            // whose outcome is unknown.
            $status = (int) $existing->response_status;

            if ($status < 100 || $status > 599) {
                return $this->inFlightResponse($shopId, $userId, $key);
            }

            // Replay: return cached response, controller is not invoked.
            $body = $existing->response_body;
            $response = response()->json($body, $status);
            // S3-09e. A claim completed before response_headers existed has
            // none, and replays exactly as it always did.
            foreach ($existing->response_headers ?? [] as $name => $value) {
                $response->headers->set($name, $value);
            }
            $response->headers->set('X-Idempotent-Replay', 'true');
            return $response;
        }

        // XR-02. Hold a session-level advisory lock on this key from before
        // the claim is staked until the claim is resolved. The operator tool
        // (mobile:idempotency-claims) must take the same lock before it may
        // release a claim, so it cannot act while this request can still
        // commit — including while it sits in a database wait, which PHP's
        // max_execution_time does not count on Linux. If the process dies,
        // PostgreSQL drops the lock together with the session, and with it any
        // uncommitted work, so a free lock means the writer can no longer
        // commit. A try-lock rather than a wait: a same-key request that finds
        // the lock held is refused as in flight, as before.
        $lockKey = self::claimLockKey($shopId, $userId, $key);

        if (! self::tryLockClaim($lockKey)) {
            return $this->inFlightResponse($shopId, $userId, $key);
        }

        try {
            // ─── First time seeing this key: STAKE THE CLAIM, then run ────────
            //
            // S3-09. This insert used to live AFTER the controller, which meant a
            // same-key retry re-ran the mutation whenever the claim never got
            // written, and meant two concurrent same-key requests both sailed past
            // the lookup above and both moved money. Staking first closes both:
            // the unique index on (shop_id, user_id, key) now admits exactly one
            // request to the controller.
            try {
                $claim = IdempotencyKey::create([
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => self::STATUS_IN_FLIGHT,
                    'response_body' => null,
                ]);
            } catch (QueryException $e) {
                // Lost the race on the unique index. The sibling that won is the
                // one executing. Refuse rather than run a second copy — the winner
                // will record its response and the client's next retry replays it.
                Log::info('EnsureIdempotency: concurrent request lost the claim race', [
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                ]);
                return $this->inFlightResponse($shopId, $userId, $key);
            } catch (Throwable $e) {
                // Cannot stake the claim → cannot promise the mutation runs once.
                // Fail CLOSED, matching the read path above. The previous code
                // failed soft here, but it could afford to: it had already run the
                // controller. We have not, so nothing is lost by refusing.
                Log::error('EnsureIdempotency: failed to stake claim', [
                    'error' => $e->getMessage(),
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'key' => $key,
                ]);
                return $this->errorResponse(
                    503,
                    'idempotency_unavailable',
                    'Idempotency check is temporarily unavailable. Please retry.',
                );
            }

            $response = $next($request);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $this->completeClaim($claim, $response, $status);

                return $response;
            }

            // ─── Non-2xx: decide whether the key is reusable ──────────────────
            //
            // 4xx is a deliberate refusal by the controller — validation failed,
            // the caller lacked a permission, the resource was in the wrong state.
            // Nothing was written, so the key is released and stays retryable,
            // preserving the behaviour this middleware has always had.
            //
            // 5xx is NOT that. A 500 can mean the controller blew up before
            // touching anything, or it can mean it committed a cash row and then
            // blew up — and from here those are indistinguishable, because the
            // router pipeline hands us a response either way. Releasing the key
            // would make the second case double-charge, which is the whole of
            // S3-09. So a 5xx leaves the claim in flight and the retry is refused.
            //
            // This is the deliberate trade: a transient 5xx that wrote nothing
            // burns that one key, and the client must surface "we could not
            // confirm this — check before re-entering it" instead of silently
            // retrying. For money that is the correct direction to fail.
            //
            // The burn is NOT bounded by anything automatic, and an earlier version
            // of this comment was wrong to say it was. It claimed
            // PruneIdempotencyKeys would reap a stuck in-flight row at 48h like any
            // other. Since S3-09b that command deliberately retains unresolved
            // claims — deleting one is precisely what re-permits the duplicate it
            // was holding back (see PruneIdempotencyKeys' docblock).
            //
            // So a burnt key stays burnt until an operator reconciles the record.
            // That is the honest cost of this policy, and it is accepted on
            // purpose: no timer here may decide that an operation whose outcome
            // nobody knows has become safe to run again.
            if ($status >= 500) {
                return $response;
            }

            $this->releaseClaim($claim, $shopId, $userId, $key);

            return $response;
        } finally {
            self::unlockClaim($lockKey);
        }
    }

    /**
     * Record how a completed request ended, so future retries can replay it.
     *
     * Fail-soft on purpose, and this is the ONE place where fail-soft is still
     * right: the mutation has already succeeded and the claim row already
     * exists holding the key. A failure here degrades the retry to a refusal
     * (the claim stays in flight), never to a re-run.
     */
    private function completeClaim(IdempotencyKey $claim, Response $response, int $status): void
    {
        try {
            $decoded = json_decode($response->getContent(), true);

            $claim->update([
                'response_status' => $status,
                'response_body' => is_array($decoded) ? $decoded : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('EnsureIdempotency: failed to record claim completion', [
                'error' => $e->getMessage(),
                'shop_id' => $claim->shop_id,
                'user_id' => $claim->user_id,
                'key' => $claim->key,
            ]);

            return;
        }

        // S3-09e. A second statement on purpose: if response_headers is ever
        // missing (migration rolled back under this code), the claim above is
        // still resolved and replays without headers — the pre-repair
        // behaviour — instead of staying in flight and refusing every retry.
        $headers = array_filter(
            array_combine(self::REPLAYED_HEADERS, array_map(
                fn (string $name) => $response->headers->get($name),
                self::REPLAYED_HEADERS,
            )),
            fn (?string $value) => $value !== null,
        );

        if ($headers === []) {
            return;
        }

        try {
            $claim->update(['response_headers' => $headers]);
        } catch (Throwable $e) {
            Log::warning('EnsureIdempotency: recorded the claim but not its replay headers', [
                'error' => $e->getMessage(),
                'shop_id' => $claim->shop_id,
                'user_id' => $claim->user_id,
                'key' => $claim->key,
            ]);
        }
    }

    /**
     * Hand a key back after a 4xx, so the caller can correct and resend.
     *
     * A failure to release is logged, not raised: the client already has its
     * 4xx, and the worst outcome is a key that refuses reuse — never a key
     * that permits a duplicate.
     */
    private function releaseClaim(IdempotencyKey $claim, int $shopId, ?int $userId, string $key): void
    {
        try {
            $claim->delete();
        } catch (Throwable $e) {
            Log::warning('EnsureIdempotency: failed to release claim after a client error', [
                'error' => $e->getMessage(),
                'shop_id' => $shopId,
                'user_id' => $userId,
                'key' => $key,
            ]);
        }
    }

    /** The advisory-lock name for one claim's logical key. Shared with the operator tool. */
    public static function claimLockKey(int $shopId, ?int $userId, string $key): string
    {
        return "idempotency-claim:{$shopId}:".($userId ?? '').":{$key}";
    }

    /**
     * Session-level, so it outlives the business transaction and ends with the
     * connection. A no-op off PostgreSQL. Requires a connection that stays with
     * this session for the whole request: direct or session-pooled, never a
     * transaction-mode pooler.
     */
    public static function tryLockClaim(string $lockKey): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        return (bool) DB::selectOne('select pg_try_advisory_lock(hashtextextended(?, 0)) as locked', [$lockKey])->locked;
    }

    /**
     * Fail-soft: an unlock that cannot run means either the connection is gone
     * (and the lock with it) or the session is inside an aborted transaction,
     * where the lock stays until the session ends. Both leave the operator tool
     * refusing, which is the safe direction.
     */
    public static function unlockClaim(string $lockKey): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::selectOne('select pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
        } catch (Throwable $e) {
            Log::warning('EnsureIdempotency: could not release a claim lock', ['error' => $e->getMessage()]);
        }
    }

    private function inFlightResponse(int $shopId, ?int $userId, string $key): JsonResponse
    {
        Log::info('EnsureIdempotency: refused a retry against an in-flight claim', [
            'shop_id' => $shopId,
            'user_id' => $userId,
            'key' => $key,
        ]);

        return $this->errorResponse(
            409,
            'idempotency_in_flight',
            'A request with this idempotency key is still in progress or did not complete. '
                . 'Check whether it took effect before retrying.',
        );
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
