<?php

namespace App\Models\Concerns;

use App\Support\Mobile;

/**
 * Canonicalise mobile columns on the way into the model.
 *
 * Validation rejects a bad number; this makes sure a GOOD one is stored in
 * exactly one spelling. Those are different jobs and both are needed — the
 * (shop_id, mobile) unique index only makes the stored STRING unique, so
 * '+91 98123 00099' and '9812300099' are two rows for one human unless
 * something collapses them before the insert.
 *
 * It lives on the model rather than at the ~20 call sites that write these
 * columns because a call site can be forgotten, and has been: the fix that
 * canonicalised Quick Bill, CSV import and the onboarding add form walked past
 * the onboarding EDIT form eighty lines below it. Every writer goes through
 * setAttribute — create, fill, update, forceFill, direct assignment, seeders,
 * imports, and whatever gets added next — so there is no list to keep in sync.
 *
 * Hydration from the database does NOT come through here (Eloquent uses
 * setRawAttributes), which is deliberate: rows written before canonicalisation
 * keep the spelling the shop typed, and reads never rewrite them.
 *
 * A value that is not a mobile number is left exactly as given rather than
 * nulled. Silently discarding input is how you turn a validation problem into a
 * data-loss problem; rejecting it is the validator's job, not this trait's.
 */
trait CanonicalisesMobileNumbers
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if (is_string($value) && in_array($key, static::$mobileColumns, true)) {
            $value = Mobile::normalize($value) ?? $value;
        }

        return parent::setAttribute($key, $value);
    }
}
