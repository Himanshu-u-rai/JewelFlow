<?php

namespace App\Http\Requests\Reporting;

use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Filters\DatePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and AUTHORIZES an export request (frozen §7.2, §10, §28).
 * Authorization is the server-side gate: a request asking for sensitive columns
 * without `reports.export_sensitive` is rejected 403 here — independently of the
 * defence-in-depth drop in ColumnPolicy — so a crafted POST or a preset cannot
 * smuggle sensitive data past the permission check (frozen §13).
 */
class ExportRequest extends FormRequest
{
    private ?ReportDefinition $definitionCache = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $registry = app(ReportRegistry::class);
        $key = (string) $this->route('report');
        if (! $registry->has($key)) {
            return false;
        }

        $def = $this->definition();
        $perms = $def->permissions;

        if (! $user->can($perms->effectiveViewGate()) || ! $user->can($perms->export)) {
            return false;
        }
        if ($perms->familyGate !== null && ! $user->can($perms->familyGate)) {
            return false;
        }
        if ($perms->edition !== null && ! ($user->shop?->hasEdition($perms->edition) ?? false)) {
            return false;
        }
        // Explicit sensitive gate (frozen §7.2): asking for sensitive without the
        // permission is a hard 403, not a silent drop.
        if ($this->requestsSensitive() && ! $user->can($perms->sensitive)) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $def = $this->definition();

        $profiles = array_map(static fn (ReportProfile $p) => $p->value, $def->profiles);
        $formats = array_values(array_filter(
            array_map(static fn (ExportFormat $f) => $f->value, $def->formats),
            static fn (string $v) => $v !== ExportFormat::Screen->value, // exports are file formats only
        ));

        return [
            'profile' => ['required', Rule::in($profiles)],
            'format' => ['required', Rule::in($formats)],
            'date_preset' => ['nullable', Rule::in(array_map(static fn (DatePreset $d) => $d->value, DatePreset::cases()))],
            'date_from' => ['nullable', 'date', 'required_if:date_preset,custom'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'required_if:date_preset,custom'],
            'fy_name' => ['nullable', 'string', 'max:16', 'required_if:date_preset,named_fy'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
            'sensitive' => ['nullable', 'array'],
            'sensitive.*' => ['string'],
            'include_sensitive' => ['nullable', 'boolean'],
            'reveal_masked' => ['nullable', 'boolean'],
            // Generic filter inputs (a report uses the subset it declares).
            'operator' => ['nullable'],
            'customer' => ['nullable'],
            'status' => ['nullable', 'string', 'max:32'],
            'metal_type' => ['nullable', 'string', 'max:32'],
            'payment_mode' => ['nullable', 'string', 'max:32'],
            'karigar' => ['nullable'],
            'lot' => ['nullable'],
            'movement_type' => ['nullable', 'string', 'max:48'],
            'days_overdue' => ['nullable', 'integer', 'min:0'],
            'age_band' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function requestsSensitive(): bool
    {
        return $this->boolean('include_sensitive') || ! empty($this->input('sensitive'));
    }

    public function definition(): ReportDefinition
    {
        return $this->definitionCache ??= app(ReportRegistry::class)->definition((string) $this->route('report'));
    }
}
