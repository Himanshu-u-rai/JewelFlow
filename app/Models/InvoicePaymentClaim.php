<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S3-07b — the durable record that a payment request was already served.
 *
 * DELIBERATELY DOES NOT USE `BelongsToShop`, AND THAT IS A SAFETY DECISION
 * -----------------------------------------------------------------------
 * Every other tenant-owned model in this codebase carries the `BelongsToShop`
 * global scope, which fails CLOSED — with no tenant context it appends
 * `whereRaw('1 = 0')` so a leak is impossible. That stance is right for a
 * model you READ TO SHOW SOMEONE. It is exactly wrong here.
 *
 * This model is read to answer "has this payment already been taken?". A query
 * that fails closed answers "no rows", which the caller cannot distinguish from
 * "never seen this key" — and the response to that is to TAKE THE PAYMENT
 * AGAIN. A fail-closed scope on a deduplication lookup converts a missing
 * tenant context into a double charge.
 *
 * So the scope is omitted and the safety is provided differently:
 *
 *   - the lookup is keyed on `invoice_id`, and the invoice arrives through a
 *     tenant-scoped route binding, so the tenant check has already happened
 *     upstream of this query;
 *   - the route carries `can:sales.create`, AND `authorizeCachedPaymentReplay`
 *     independently re-checks tenant context, invoice ownership and the same
 *     ability before any claim body is returned. Both are live decisions, so a
 *     revoked permission stops a replay as well as a fresh payment (P-13);
 *   - `shop_id` on the row is asserted against the bound invoice's shop in
 *     `findPaymentClaim` before the claim is handed back, and a mismatch is a
 *     refusal rather than a miss (P-12);
 *   - a DB failure on the lookup is answered with a refusal (503), never with
 *     a fall-through to processing.
 *
 * CORRECTION TO AN EARLIER VERSION OF THIS NOTE — TWICE.
 *
 * 1. It claimed `shop_id` "is stored and asserted by the controller". The
 *    storing was true; the asserting was not. Nothing read the column until
 *    P-12 added the check now described above. A comment asserting a control
 *    that does not exist is worse than silence, because it stops the next
 *    reader from looking.
 *
 * 2. It summarised the principle as "fail-closed is about what you will SHOW".
 *    THAT IS WRONG AND IS WITHDRAWN. Failing closed protects against
 *    unauthorized MUTATION every bit as much as unauthorized disclosure — a
 *    missing tenant context must stop the operation outright, not merely
 *    prevent a read.
 *
 *    The actual, narrower reason the global scope is omitted here is about this
 *    one query's failure SHAPE, not about mutations mattering less: on a
 *    deduplication lookup, `whereRaw('1 = 0')` does not refuse — it returns a
 *    well-formed empty result that the caller reads as "new request", and that
 *    reading is what authorizes the second charge. The scope would convert a
 *    missing context into a mutation instead of preventing one.
 *
 *    So the obligation is not dropped, it is relocated and made explicit: every
 *    path that could act on a missing or unusable claim REFUSES — 403 on
 *    context or permission, 404 on a foreign invoice, 409 on an inconsistent
 *    row, 503 on a lookup error. None of them falls through into taking a
 *    payment. Fail-closed still governs the mutation; it is simply enforced by
 *    those refusals rather than by a scope that cannot express one.
 */
class InvoicePaymentClaim extends Model
{
    protected $table = 'invoice_payment_claims';

    protected $fillable = [
        'invoice_id',
        'shop_id',
        'user_id',
        'key',
        'request_hash',
        'response_status',
        'response_body',
    ];

    protected $casts = [
        'invoice_id'      => 'integer',
        'shop_id'         => 'integer',
        'user_id'         => 'integer',
        'response_status' => 'integer',
        'response_body'   => 'array',
    ];
}
