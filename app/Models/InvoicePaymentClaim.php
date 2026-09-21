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
 *   - `shop_id` is stored and asserted by the controller before any claim body
 *     is returned, so reading a claim is still an authorized act;
 *   - a DB failure on the lookup is answered with a refusal (503), never with
 *     a fall-through to processing.
 *
 * The general rule this is an instance of: fail-closed is about what you will
 * SHOW. For what you will DO, the safe default is to refuse to act, which here
 * means refusing the request rather than quietly treating it as new.
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
