<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeHumanTextInput
{
    /**
     * Only normalize fields that are human-facing text labels/details.
     * IDs/codes/passwords/emails/numeric fields remain untouched.
     */
    private array $exactNameTextKeys = [
        'name',
        'first_name',
        'last_name',
        'owner_first_name',
        'owner_last_name',
        'contact_person',
        'display_name',
        'design',
        'description',
        'item_description',
        'category',
        'sub_category',
        'stone_type',
        'source_name',
        'address',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
    ];

    /**
     * Long free-text fields where we only capitalize line starts,
     * not every word.
     */
    private array $exactSentenceTextKeys = [
        'notes',
        'terms_and_conditions',
    ];

    /**
     * Sensitive/identifier fields that must never be auto-transformed.
     */
    private array $exactExcludedKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'email',
        'owner_email',
        'mobile',
        'owner_mobile',
        'phone',
        'otp',
        'secret',
        'token',
        'remember_token',
        'api_key',
        'access_token',
        'barcode',
        'huid',
        'invoice_number',
        'invoice_prefix',
        'invoice_start_number',
        'invoice_sequence',
        'design_code',
        'customer_code',
        'repair_number',
        'lot_number',
        'import_reference',
        'gst_number',
        'pan',
        'id_number',
        'upi_id',
        'slug',
        'normalized_name',
    ];

    private array $excludedContains = [
        '_id',
        '_code',
        'password',
        'email',
        'token',
        'secret',
        'barcode',
        'invoice_',
        'huid',
        'otp',
    ];

    /**
     * Dynamic dictionaries whose keys are foreign data (spreadsheet column
     * headers, canonical field slugs), never semantic field names. Recursing
     * into these lets an arbitrary source column literally named "Notes" or
     * "Name" collide with the generic key heuristics below and silently
     * mangle a stored value (e.g. an enum like "ignored" -> "Ignored"),
     * breaking validation without a clear cause. Left untouched wholesale.
     */
    private array $opaqueContainerKeys = [
        'mapping',
        'column_decisions',
        'sheets',
        'making_defaults',
        'tax_defaults',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $request->merge($this->normalizeArray($request->all()));
        }

        return $next($request);
    }

    private function normalizeArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                if (in_array(strtolower((string) $key), $this->opaqueContainerKeys, true)) {
                    continue;
                }

                $payload[$key] = $this->normalizeArray($value);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $mode = $this->normalizationModeForKey((string) $key);
            if ($mode === 'name') {
                $payload[$key] = $this->normalizeNameText($value);
            } elseif ($mode === 'sentence') {
                $payload[$key] = $this->normalizeSentenceText($value);
            }
        }

        return $payload;
    }

    private function normalizationModeForKey(string $key): ?string
    {
        $key = strtolower($key);

        if ($this->isExcludedKey($key)) {
            return null;
        }

        if (in_array($key, $this->exactSentenceTextKeys, true)) {
            return 'sentence';
        }

        if (in_array($key, $this->exactNameTextKeys, true)) {
            return 'name';
        }

        if (str_ends_with($key, '_name')) {
            return 'name';
        }

        if (str_contains($key, 'address')) {
            return 'name';
        }

        return null;
    }

    private function isExcludedKey(string $key): bool
    {
        if (in_array($key, $this->exactExcludedKeys, true)) {
            return true;
        }

        foreach ($this->excludedContains as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tidy whitespace. Change no letter's case, ever.
     *
     * A name is the operator's own text and is stored verbatim. This used to run
     * `MB_CASE_TITLE`, which lowercases every character it does not capitalize:
     * "JewelFlows" -> "Jewelflows", "RK Jewellers" -> "Rk Jewellers",
     * "TBZ" -> "Tbz", "QA Gold Item" -> "Qa Gold Item". The loss is not
     * recoverable from the stored value.
     *
     * A first correction kept Title Case for all-lowercase input, on the theory
     * that "abc jewellers" carried no case decision to protect. That theory was
     * wrong: an operator who types lowercase has still chosen how their own shop
     * name is spelled, and "no uppercase letter" is not evidence of indifference.
     * Presentation is the view layer's job — `capitalize`/`uppercase` in CSS
     * changes nothing in the database and is reversible. Rewriting the stored
     * value is neither.
     *
     * Case-insensitive UNIQUENESS does not depend on this: App\Rules\
     * UniqueNameIgnoringCase compares on LOWER(TRIM(...)), matching the
     * `normalized_name` indexes, so "ABC" and "abc" still collide.
     *
     * Whitespace is different in kind and stays: leading/trailing and doubled
     * spaces are typos, not decisions, and `normalized_name` collapses them
     * anyway — so tidying here keeps validation agreeing with the index.
     */
    private function normalizeNameText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function normalizeSentenceText(string $value): string
    {
        $value = trim(str_replace("\r\n", "\n", $value));
        if ($value === '') {
            return $value;
        }

        $lines = explode("\n", $value);
        $normalized = array_map(function (string $line): string {
            $line = trim($line);
            if ($line === '') {
                return $line;
            }

            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

            if (preg_match('/^(\p{L})(.*)$/u', $line, $matches) === 1) {
                return mb_strtoupper($matches[1], 'UTF-8') . $matches[2];
            }

            return $line;
        }, $lines);

        return implode("\n", $normalized);
    }
}
