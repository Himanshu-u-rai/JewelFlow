<?php

namespace App\Http\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * EmitsEntityTag — RFC 7232 optimistic concurrency control for mobile v1.
 *
 * Provides a stable, resource-typed ETag for any Eloquent model and the
 * matching `If-Match` precondition check used by mutation endpoints. A
 * stale mobile copy attempting to edit a row that has since changed
 * fails fast with HTTP 412 instead of silently overwriting another
 * cashier's edits.
 *
 * Contract:
 *   - entityTagFor($model) → quoted, RFC-7232-compliant strong validator.
 *     Format: "<sha256-hex>:<class-basename>:<id>". Hash inputs are
 *     (id | updated_at ISO-8601 | class basename) so:
 *       * Same row + same updated_at  ⇒ same ETag (idempotent reads)
 *       * Same id across different models (Customer 42 vs Item 42)
 *         produce different ETags (no cross-resource collisions)
 *       * The visible suffix lets server logs disambiguate at a glance.
 *
 *   - assertIfMatchOrFail($request, $model):
 *       * If `If-Match` header is ABSENT      → 428 Precondition Required
 *         envelope error code "precondition_required".
 *       * If present but the value does not   → 412 Precondition Failed
 *         match the current entityTag,           envelope error code
 *         the response also carries the          "precondition_failed"
 *         current ETag in params so the          with params { expected,
 *         client can refresh.                    received }.
 *       * If matches                            → void (request proceeds)
 *
 * Errors are emitted as JSON envelope error-arrays via HttpResponseException;
 * the mobile.envelope middleware tops them up with meta/data fields. They
 * do NOT flow through the bootstrap HttpException renderer because we want
 * the controller-level `params` block to survive intact (the bootstrap
 * mapper would discard params).
 */
trait EmitsEntityTag
{
    /**
     * Build the strong ETag validator for a model.
     *
     * Quoted per RFC 7232 §2.3 (opaque-tag = DQUOTE *etagc DQUOTE).
     */
    public function entityTagFor(Model $model): string
    {
        $class = class_basename($model);
        $id = (string) $model->getKey();

        // updated_at may be null on freshly-loaded mocks, but for real
        // mobile-v1 resources it is always populated by the timestamps
        // contract. Falling back to created_at keeps the ETag defined.
        $updatedAt = $model->updated_at
            ?? $model->created_at
            ?? null;

        $stamp = $updatedAt instanceof \DateTimeInterface
            ? $updatedAt->format(\DateTimeInterface::ATOM)
            : (string) $updatedAt;

        $hash = hash('sha256', $id . '|' . $stamp . '|' . $class);

        return '"' . $hash . ':' . strtolower($class) . ':' . $id . '"';
    }

    /**
     * Enforce optimistic concurrency on a mutation request.
     *
     * @throws HttpResponseException 428 if header missing, 412 if mismatched.
     */
    public function assertIfMatchOrFail(Request $request, Model $model): void
    {
        $client = $request->headers->get('If-Match');

        if (! is_string($client) || trim($client) === '') {
            throw new HttpResponseException(response()->json([
                'errors' => [[
                    'code'    => 'precondition_required',
                    'message' => 'If-Match header is required for this mutation.',
                ]],
            ], 428));
        }

        $current = $this->entityTagFor($model);

        // Tolerate a single client-supplied tag (the common case). The
        // mobile contract does not authorise lists or weak validators.
        $clientTrimmed = trim($client);

        if ($clientTrimmed !== $current) {
            throw new HttpResponseException(response()->json([
                'errors' => [[
                    'code'    => 'precondition_failed',
                    'message' => 'The resource has changed since you last loaded it. Please refresh and try again.',
                    'params'  => [
                        'expected' => $current,
                        'received' => $clientTrimmed,
                    ],
                ]],
            ], 412));
        }
    }
}
