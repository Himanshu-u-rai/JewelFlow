<?php

namespace App\Http\Middleware;

use App\Services\MetalRegistry;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile API v1 — canonical response envelope.
 *
 * Every response that flows through this middleware is wrapped in the
 * platform contract shape:
 *
 *   {
 *     "data":   <object | array | null>,
 *     "meta":   { request_id, server_time, api_version, registry_version,
 *                 pagination?: { cursor, next_cursor, prev_cursor, page_size, has_more } },
 *     "errors": [ { code, field?, message, params? } ]
 *   }
 *
 * Controllers under `/api/mobile/v1/` SHOULD return raw data (arrays,
 * Spatie Data DTOs, Eloquent paginators, or models) — they do NOT build
 * the envelope themselves. The middleware handles:
 *
 *  - Spatie Data unwrap (Data / DataCollection → array)
 *  - Eloquent CursorPaginator → data + pagination meta
 *  - JsonResponse passthrough for already-shaped responses (legacy
 *    controllers, exception handlers, etc.) — if the body already has
 *    `data` AND `meta` AND `errors`, we leave it alone
 *  - Error normalisation: Laravel validation errors and HTTP exceptions
 *    are reshaped into the canonical `errors[]` array
 *  - request_id propagation via X-Request-Id header (echoed back)
 *
 * The middleware is intentionally lenient on input shape and strict on
 * output shape — every successful response, every error, looks the same.
 */
class MobileEnvelope
{
    private const API_VERSION = '1';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        $response = $next($request);

        // Non-JSON responses (file downloads, redirects on accidental web
        // hits) flow through untouched. Mobile v1 always declares JSON.
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $original = $response->getData(true);
        $status   = $response->getStatusCode();

        // If the body is already enveloped (idempotency replay, error
        // handler), leave it but re-stamp the meta.
        if ($this->looksAlreadyEnveloped($original)) {
            $original['meta'] = array_merge(
                $this->baseMeta($requestId),
                $original['meta'] ?? []
            );
            $response->setData($original);
            $response->headers->set('X-Request-Id', $requestId);
            return $response;
        }

        // Build the envelope.
        $envelope = [
            'data'   => $this->extractData($original),
            'meta'   => array_merge(
                $this->baseMeta($requestId),
                $this->paginationMeta($original)
            ),
            'errors' => $this->extractErrors($original, $status),
        ];

        $response->setData($envelope);
        $response->headers->set('X-Request-Id', $requestId);
        return $response;
    }

    private function baseMeta(string $requestId): array
    {
        return [
            'request_id'       => $requestId,
            'server_time'      => now()->toIso8601String(),
            'api_version'      => self::API_VERSION,
            'registry_version' => MetalRegistry::registryVersion(),
        ];
    }

    /** A body that already has data + meta + errors is passed through. */
    private function looksAlreadyEnveloped(mixed $body): bool
    {
        return is_array($body)
            && array_key_exists('data', $body)
            && array_key_exists('meta', $body)
            && array_key_exists('errors', $body);
    }

    private function extractData(mixed $body): mixed
    {
        // Laravel paginator shape: { data: [...], current_page, ... }
        if (is_array($body) && array_key_exists('data', $body) && array_key_exists('per_page', $body)) {
            return $body['data'];
        }
        // Laravel validation error shape: { message, errors: {...} } — body becomes null
        if (is_array($body) && array_key_exists('errors', $body) && array_key_exists('message', $body) && ! array_key_exists('data', $body)) {
            return null;
        }
        // Plain error shape: { message } or { error, message }
        if (is_array($body) && (array_key_exists('error', $body) || (array_key_exists('message', $body) && count($body) <= 2))) {
            return null;
        }
        return $body;
    }

    private function paginationMeta(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        // Cursor paginator shape: data + next_cursor + prev_cursor + per_page + path
        if (array_key_exists('next_cursor', $body) && array_key_exists('prev_cursor', $body)) {
            return [
                'pagination' => [
                    'cursor'      => $body['prev_cursor'] ?? null,
                    'next_cursor' => $body['next_cursor'] ?? null,
                    'page_size'   => (int) ($body['per_page'] ?? 0),
                    'has_more'    => ! empty($body['next_cursor']),
                ],
            ];
        }

        // Length-aware paginator (legacy offset pagination — still
        // works through v1 but cursor is preferred for new endpoints).
        if (array_key_exists('current_page', $body) && array_key_exists('per_page', $body) && array_key_exists('last_page', $body)) {
            return [
                'pagination' => [
                    'page'       => (int) $body['current_page'],
                    'page_size'  => (int) $body['per_page'],
                    'last_page'  => (int) $body['last_page'],
                    'total'      => (int) ($body['total'] ?? 0),
                    'has_more'   => (int) $body['current_page'] < (int) $body['last_page'],
                ],
            ];
        }

        return [];
    }

    private function extractErrors(mixed $body, int $status): array
    {
        if ($status < 400) {
            return [];
        }

        if (! is_array($body)) {
            return [['code' => 'unknown', 'message' => 'An error occurred.']];
        }

        // Laravel validation error shape: errors is an associative array
        // of field => string[]. Normalise to objects.
        if (is_array($body['errors'] ?? null) && $this->isAssociativeFieldMap($body['errors'])) {
            $out = [];
            foreach ($body['errors'] as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $out[] = [
                        'code'    => $this->guessCode($field, $message),
                        'field'   => $field,
                        'message' => $message,
                    ];
                }
            }
            return $out;
        }

        // Already an array of error objects → pass through.
        if (is_array($body['errors'] ?? null)) {
            return $body['errors'];
        }

        // Single-message shape: { error, message } or { message }
        $code = $body['error']
            ?? $body['code']
            ?? $this->statusCode($status);
        $msg = $body['message'] ?? 'Request failed.';
        return [['code' => $code, 'message' => $msg]];
    }

    private function isAssociativeFieldMap(array $errors): bool
    {
        foreach (array_keys($errors) as $k) {
            if (! is_string($k)) {
                return false;
            }
        }
        return true;
    }

    private function guessCode(string $field, string $message): string
    {
        // Lightweight heuristic — controllers that want a stable code
        // should pass it through the body's errors[] explicitly. The
        // default is "validation.<field>".
        return 'validation.' . $field;
    }

    private function statusCode(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'permission_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 412 => 'precondition_failed',
            $status === 422 => 'unprocessable',
            $status === 429 => 'too_many_requests',
            $status === 503 => 'service_unavailable',
            $status >= 500  => 'server_error',
            default         => 'request_failed',
        };
    }
}
