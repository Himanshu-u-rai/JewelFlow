<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportingPreset;
use App\Services\Reporting\Definition\ColumnTier;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shop-wide named export presets (frozen §8, §21; GAP 1 of REPORT_EXPORT_GAP_AUDIT.md).
 *
 * A preset is a saved {report_key + profile + format + date scope + column
 * selection + filters} that pre-fills the export panel. It is a CONVENIENCE
 * over the existing export flow — it NEVER carries its own export authority:
 *
 *   - Reads/writes are shop-scoped (BelongsToShop global scope + an explicit
 *     shop_id backstop on every mutating action).
 *   - Applying a preset only pre-fills the panel form. The real export still
 *     goes through ExportRequest::authorize(), which is the single server-side
 *     gate (view + export + sensitive). A saved preset therefore cannot bypass
 *     any permission (frozen §13, §21 guardrail).
 *   - Sensitive column keys are stripped at save time unless the saver holds the
 *     report's sensitive permission, and at apply time they are only surfaced to
 *     a user who still holds it. The export endpoint re-checks regardless.
 *
 * Per frozen §8: anyone with `reports.export` may USE/apply presets; creating
 * and editing the shop-wide set is owner/manager only.
 */
class PresetController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry)
    {
    }

    /** List this shop's presets for a report (JSON; consumed by the export panel). */
    public function index(Request $request, string $report): JsonResponse
    {
        $this->assertReportViewable($request, $report);

        $presets = ReportingPreset::query()
            ->where('report_key', $report)
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (ReportingPreset $p) => $this->present($p));

        return response()->json(['presets' => $presets]);
    }

    /** Save the current export selection as a named, shop-wide preset. */
    public function store(Request $request, string $report): RedirectResponse
    {
        $definition = $this->assertManageable($request, $report);
        $user = $request->user();

        $validated = $this->validatePayload($request, $definition);

        // Defence in depth: a saver without the sensitive permission cannot bake
        // sensitive columns into a shop-wide preset (the export gate would block
        // them anyway; this keeps the saved record honest).
        $columns = $this->sanitizeColumns($validated['columns'] ?? [], $definition, $user);

        $uniqueName = Rule::unique('reporting_presets')
            ->where(fn ($q) => $q->where('shop_id', $user->shop_id)->where('report_key', $report));
        $request->validate([
            'name' => ['required', 'string', 'max:120', $uniqueName],
        ], [], ['name' => 'preset name']);

        ReportingPreset::create([
            'name' => trim($validated['name']),
            'report_key' => $report,
            'profile' => $validated['profile'] ?? null,
            'format' => $validated['format'] ?? null,
            'columns' => $columns,
            'filters' => $validated['filters'] ?? [],
            'scope' => ReportingPreset::SCOPE_SHOP,
            'created_by' => $user->id,
        ]);

        return back()->with('status', "Preset \"{$validated['name']}\" saved.");
    }

    /** Rename / re-save a preset (owner/manager, own shop only). */
    public function update(Request $request, string $report, ReportingPreset $preset): RedirectResponse
    {
        $definition = $this->assertManageable($request, $report);
        $this->assertOwnShop($request, $preset, $report);
        $user = $request->user();

        $validated = $this->validatePayload($request, $definition);
        $columns = $this->sanitizeColumns($validated['columns'] ?? [], $definition, $user);

        $uniqueName = Rule::unique('reporting_presets')
            ->where(fn ($q) => $q->where('shop_id', $user->shop_id)->where('report_key', $report))
            ->ignore($preset->id);
        $request->validate([
            'name' => ['required', 'string', 'max:120', $uniqueName],
        ], [], ['name' => 'preset name']);

        $preset->update([
            'name' => trim($validated['name']),
            'profile' => $validated['profile'] ?? null,
            'format' => $validated['format'] ?? null,
            'columns' => $columns,
            'filters' => $validated['filters'] ?? [],
        ]);

        return back()->with('status', "Preset \"{$validated['name']}\" updated.");
    }

    /** Delete a preset (owner/manager, own shop only). */
    public function destroy(Request $request, string $report, ReportingPreset $preset): RedirectResponse
    {
        $this->assertManageable($request, $report);
        $this->assertOwnShop($request, $preset, $report);

        $name = $preset->name;
        $preset->delete();

        return back()->with('status', "Preset \"{$name}\" deleted.");
    }

    // ── guards ────────────────────────────────────────────────────────────────

    /** A real, viewable report; user may at least view+export it. */
    private function assertReportViewable(Request $request, string $report): void
    {
        abort_unless($this->registry->has($report), 404);
        $definition = $this->registry->definition($report);
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($user->can($definition->permissions->effectiveViewGate()), 403);
        abort_unless($user->can($definition->permissions->export), 403);
    }

    /** Managing the shop-wide preset set is owner/manager only (frozen §8). */
    private function assertManageable(Request $request, string $report)
    {
        abort_unless($this->registry->has($report), 404);
        $definition = $this->registry->definition($report);
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($user->can($definition->permissions->export), 403);
        abort_unless($user->isOwner() || $user->isManager(), 403, 'Only an owner or manager can manage export presets.');

        return $definition;
    }

    /** The preset must belong to the caller's shop AND the route's report. */
    private function assertOwnShop(Request $request, ReportingPreset $preset, string $report): void
    {
        // BelongsToShop already scopes route-model binding to the tenant, so a
        // cross-shop id 404s before reaching here. This is the explicit backstop.
        abort_unless((int) $preset->shop_id === (int) $request->user()->shop_id, 404);
        abort_unless($preset->report_key === $report, 404);
    }

    // ── validation / sanitisation ──────────────────────────────────────────────

    /**
     * Validate the saved selection against the report's own definition: the
     * profile and format must be ones this report actually offers, columns are
     * strings, filters are a flat array. Invalid report keys are rejected by the
     * registry guard above; invalid profile/format are rejected here.
     */
    private function validatePayload(Request $request, $definition): array
    {
        $profiles = array_map(static fn (ReportProfile $p) => $p->value, $definition->profiles);
        $formats = array_values(array_filter(
            array_map(static fn (ExportFormat $f) => $f->value, $definition->formats),
            static fn (string $v) => $v !== ExportFormat::Screen->value,
        ));

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'profile' => ['nullable', Rule::in($profiles)],
            'format' => ['nullable', Rule::in($formats)],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
            'filters' => ['nullable', 'array'],
        ]);
    }

    /**
     * Keep only column keys that exist on the report, and drop sensitive keys
     * unless the saver holds the sensitive permission. This never widens access —
     * it only prevents a preset from advertising columns it has no right to.
     *
     * @param  string[]  $columns
     * @return string[]
     */
    private function sanitizeColumns(array $columns, $definition, $user): array
    {
        $allowed = [];
        $sensitiveKeys = [];
        foreach ($definition->columns as $col) {
            $allowed[$col->key] = true;
            if ($col->tier === ColumnTier::Sensitive) {
                $sensitiveKeys[$col->key] = true;
            }
        }

        $canSensitive = $user->can($definition->permissions->sensitive);

        return array_values(array_filter($columns, static function ($key) use ($allowed, $sensitiveKeys, $canSensitive) {
            if (! isset($allowed[$key])) {
                return false;
            }
            if (isset($sensitiveKeys[$key]) && ! $canSensitive) {
                return false;
            }

            return true;
        }));
    }

    private function present(ReportingPreset $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'report_key' => $p->report_key,
            'profile' => $p->profile,
            'format' => $p->format,
            'columns' => $p->columns ?? [],
            'filters' => $p->filters ?? [],
            'created_by' => $p->creator?->name,
            'updated_at' => optional($p->updated_at)->toDateString(),
        ];
    }
}
