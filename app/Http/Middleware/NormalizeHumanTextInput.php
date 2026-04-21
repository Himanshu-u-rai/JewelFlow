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
    private array $exactTitleTextKeys = [
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
                $payload[$key] = $this->normalizeArray($value);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $mode = $this->normalizationModeForKey((string) $key);
            if ($mode === 'title') {
                $payload[$key] = $this->normalizeTitleText($value);
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

        if (in_array($key, $this->exactTitleTextKeys, true)) {
            return 'title';
        }

        if (str_ends_with($key, '_name')) {
            return 'title';
        }

        if (str_contains($key, 'address')) {
            return 'title';
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

    private function normalizeTitleText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
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
