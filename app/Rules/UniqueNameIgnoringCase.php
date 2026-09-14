<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * "You already have one of these" — decided on the name's meaning, not its
 * spelling.
 *
 * Until NormalizeHumanTextInput stopped overruling the operator, every `name`
 * reaching validation had been forced to Title Case, so a plain
 * `Rule::unique('name')` compared two already-case-folded strings and behaved
 * case-insensitively by accident. Preserving the operator's capitalization took
 * that accident away, and the rules that leaned on it had to say what they
 * actually meant.
 *
 * This is not merely tidier. `categories` and `sub_categories` carry a UNIQUE
 * index on `normalized_name`, which is `lower(trim(...))` — so a case-sensitive
 * validator lets "RINGS" past while the database still refuses it, turning a
 * readable field error into a 500. Validation now agrees with the index.
 *
 * Comparison is `LOWER(TRIM(name))`, matching how `normalized_name` is built.
 * Scope is always passed explicitly — including `shop_id` — because this uses
 * the query builder and no tenant scope applies to it.
 */
class UniqueNameIgnoringCase implements ValidationRule
{
    /**
     * @param  string               $table    table to search
     * @param  array<string, mixed> $scope    column => value, must include shop_id
     * @param  int|null             $ignoreId row to exclude, for updates
     * @param  string               $noun     what to call the thing in the message
     */
    public function __construct(
        private string $table,
        private array $scope,
        private ?int $ignoreId = null,
        private string $noun = 'entry',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value)) {
            return; // `string` handles the shape; this rule only answers "taken?"
        }

        $needle = self::normalize((string) $value);
        if ($needle === '') {
            return; // `required` owns emptiness
        }

        // ponytail: the column is hardcoded `name` on purpose. Every caller uses
        // it, and a caller-supplied column would be interpolated into raw SQL.
        //
        // The SQL mirrors the stored `normalized_name` expression exactly —
        // lower(trim(collapse-inner-whitespace)) — because agreeing with the
        // database's own UNIQUE index is the entire job. Trimming alone left one
        // gap: a legacy row stored as "Gold  Rings" (two spaces) normalizes to
        // "gold rings" in the index but not in a TRIM-only comparison, so a new
        // "Gold Rings" would pass validation and then hit the index as a 500.
        // Fresh input cannot reach that state — NormalizeHumanTextInput collapses
        // runs of whitespace before validation ever sees the value — but rows
        // that predate the middleware can, and they are exactly the rows an
        // operator is most likely to be retyping.
        $taken = DB::table($this->table)
            ->where($this->scope)
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->whereRaw("LOWER(TRIM(REGEXP_REPLACE(name, '\\s+', ' ', 'g'))) = ?", [$needle])
            ->exists();

        if ($taken) {
            $fail("You already have a {$this->noun} called \"{$value}\". Edit that one instead of adding another.");
        }
    }

    /** The PHP half of the comparison above. Same three steps, same order. */
    private static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
