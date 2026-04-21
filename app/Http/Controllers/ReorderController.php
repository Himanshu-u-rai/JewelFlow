<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RespondsDynamically;
use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\SubCategory;
use App\Models\Vendor;
use App\Services\ReorderAlertService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReorderController extends Controller
{
    use RespondsDynamically;
    public function __construct(protected ReorderAlertService $reorderService) {}

    public function index()
    {
        $shopId = auth()->user()->shop_id;

        $rules = ReorderRule::where('shop_id', $shopId)
            ->with('vendor')
            ->latest()
            ->get();

        $alerts = $this->reorderService->getAlerts($shopId);

        return view('reorder.index', compact('rules', 'alerts'));
    }

    public function create()
    {
        return view('reorder.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateAndNormalizeRulePayload($request);
        $data['is_active'] = true;

        ReorderRule::create($data);

        $this->reorderService->clearCache(auth()->user()->shop_id);

        return redirect()->route('reorder.index')->with('success', 'Reorder rule created.');
    }

    public function edit(ReorderRule $rule)
    {
        $this->authorize('update', $rule);

        return view('reorder.edit', array_merge(['rule' => $rule], $this->formData()));
    }

    public function update(Request $request, ReorderRule $rule)
    {
        $this->authorize('update', $rule);

        $data = $this->validateAndNormalizeRulePayload($request);
        $data['is_active'] = $request->boolean('is_active');

        $rule->update($data);

        $this->reorderService->clearCache(auth()->user()->shop_id);

        return redirect()->route('reorder.index')->with('success', 'Reorder rule updated.');
    }

    public function destroy(ReorderRule $rule)
    {
        $this->authorize('delete', $rule);

        $shopId = $rule->shop_id;
        $rule->delete();

        $this->reorderService->clearCache($shopId);

        return $this->dynamicRedirect('reorder.index', [], 'Reorder rule deleted.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Shared data for create and edit forms. */
    private function formData(): array
    {
        $shopId = auth()->user()->shop_id;

        $vendors    = Vendor::where('shop_id', $shopId)->active()->orderBy('name')->get();
        $categories = Category::where('shop_id', $shopId)->orderBy('name')->get();

        $subCategoryMap = SubCategory::query()
            ->with('category:id,name')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SubCategory $sc) => (string) optional($sc->category)->name)
            ->map(fn ($rows) => $rows->pluck('name')->unique()->values()->all())
            ->filter(fn ($rows, $catName) => trim((string) $catName) !== '')
            ->all();

        return compact('vendors', 'categories', 'subCategoryMap');
    }

    /** Validate and normalise the rule payload for both store and update. */
    private function validateAndNormalizeRulePayload(Request $request): array
    {
        $shopId = (int) auth()->user()->shop_id;

        $data = $request->validate([
            'category'           => ['nullable', 'string', 'max:255'],
            'sub_category'       => ['nullable', 'string', 'max:255'],
            'min_stock_threshold' => ['required', 'integer', 'min:1'],
            'vendor_id'          => [
                'nullable',
                Rule::exists('vendors', 'id')->where(fn ($q) => $q->where('shop_id', $shopId)),
            ],
            'is_active'          => ['sometimes', 'boolean'],
        ], [
            'vendor_id.exists' => 'Selected vendor does not belong to your shop.',
        ]);

        // Resolve and validate category against this shop's records.
        $normalizedCategory = ReorderRule::normalizeName((string) ($data['category'] ?? ''));
        if ($normalizedCategory === '') {
            $data['category'] = null;
        } else {
            $category = Category::where('shop_id', $shopId)
                ->where('normalized_name', $normalizedCategory)
                ->first();

            if (!$category) {
                throw ValidationException::withMessages([
                    'category' => 'Please select a valid category from your shop.',
                ]);
            }

            $data['category'] = $category->name;
        }

        // Resolve and validate sub-category.
        $normalizedSubCategory = ReorderRule::normalizeName((string) ($data['sub_category'] ?? ''));
        if ($normalizedSubCategory === '') {
            $data['sub_category'] = null;
        } else {
            if (!$data['category']) {
                throw ValidationException::withMessages([
                    'sub_category' => 'Select a category before choosing a sub-category.',
                ]);
            }

            $category = Category::where('shop_id', $shopId)->where('name', $data['category'])->first();

            $subCategory = $category
                ? SubCategory::where('category_id', $category->id)
                    ->where('normalized_name', $normalizedSubCategory)
                    ->first()
                : null;

            if (!$subCategory) {
                throw ValidationException::withMessages([
                    'sub_category' => 'Please select a valid sub-category for the chosen category.',
                ]);
            }

            $data['sub_category'] = $subCategory->name;
        }

        return $data;
    }
}
