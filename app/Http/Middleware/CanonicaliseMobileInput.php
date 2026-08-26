<?php

namespace App\Http\Middleware;

use App\Support\Mobile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites mobile-number fields to their canonical ten digits BEFORE validation.
 *
 * The model mutator (CanonicalisesMobileNumbers) guarantees the column only ever
 * holds one spelling. On its own that is not enough, because validation runs
 * first and against a different string: `Rule::unique` compares the RAW submitted
 * value, so '+91 98123 00099' finds no match against a stored '9812300099',
 * passes, and then hits the (shop_id, mobile) unique index as '9812300099' — a
 * 500 on a form the operator filled in correctly, just differently. Typing the
 * identical spelling gets a clean validation error, so without this the penalty
 * for using spaces is a crash.
 *
 * The gap only exists because the forms were widened from `digits:10` to
 * IndianMobileRule. Accepting more spellings and canonicalising the write are
 * only safe together if the uniqueness check sees the string the insert will.
 *
 * Doing it here rather than in each controller is deliberate: there are a dozen
 * places pairing IndianMobileRule with a uniqueness check, and a list of call
 * sites to keep in sync is the exact failure mode the mutator was introduced to
 * remove. One boundary, every route.
 */
class CanonicaliseMobileInput
{
    /**
     * Every request key validated by IndianMobileRule. Enumerated from the rule's
     * call sites, not guessed — a key that is missing here silently keeps the old
     * behaviour, which is a defect that looks like nothing.
     *
     * @var array<int, string>
     */
    private const KEYS = [
        'mobile',
        'mobile_number',
        'new_mobile',
        'new_mobile_number',
        'customer_mobile',
        'owner_mobile',
        'phone',
        'shop_whatsapp',
        'social_whatsapp',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $replace = [];

        foreach (self::KEYS as $key) {
            $value = $request->input($key);

            // Only rewrite a value that IS an Indian mobile. Anything else —
            // a landline, a typo, a nine-digit scrap, an empty string — is left
            // exactly as typed so IndianMobileRule can name the real problem
            // instead of reporting on a string the operator never entered.
            if (is_string($value) && ($canonical = Mobile::normalize($value)) !== null) {
                $replace[$key] = $canonical;
            }
        }

        if ($replace !== []) {
            $request->merge($replace);
        }

        return $next($request);
    }
}
