<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ArchivesParties;
use App\Http\Concerns\RespondsDynamically;
use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    use ArchivesParties, RespondsDynamically;

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // MASTERS PART 3: the search filter is reused for the query AND the tab
        // counts, so the number on a tab always matches what clicking it shows.
        $search = $request->filled('search') ? $request->search : null;
        $applySearch = function ($q) use ($search) {
            if ($search !== null) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'ilike', "%{$search}%")
                        ->orWhere('contact_person', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('gst_number', 'ilike', "%{$search}%");
                });
            }

            return $q;
        };

        // Aggregate stats over the full dataset — not the paginated page.
        $stats = Vendor::where('shop_id', $shopId)
            ->selectRaw("
                count(*) as total_count,
                count(*) filter (where is_active IS TRUE) as active_count,
                count(*) filter (where gst_number is not null and gst_number <> '') as gst_count
            ")
            ->first();

        // Default is Active: archived vendors are retained, not shown by
        // default. 'inactive' is kept as an alias so existing bookmarks and
        // links into ?status=inactive keep working.
        $status = match ($request->input('status')) {
            'archived', 'inactive' => 'archived',
            'all' => 'all',
            default => 'active',
        };

        $statusCounts = $applySearch(Vendor::where('shop_id', $shopId))
            ->selectRaw("
                count(*) as all_count,
                count(*) filter (where is_active IS TRUE) as active_count,
                count(*) filter (where is_active IS FALSE) as archived_count
            ")
            ->first();

        $query = $applySearch(Vendor::where('shop_id', $shopId));

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->archived();
        }

        $vendors = $query->latest()->paginate(15)->withQueryString();

        return view('vendors.index', compact('vendors', 'stats', 'status', 'statusCounts', 'search'));
    }

    public function create()
    {
        return view('vendors.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateVendorPayload($request);

        if (isset($data['gst_number'])) {
            $data['gst_number'] = strtoupper(trim($data['gst_number']));
        }

        $vendor = Vendor::create($data);

        if ($request->expectsJson()) {
            return response()->json([
                'id'        => $vendor->id,
                'name'      => $vendor->name,
                'is_active' => $vendor->is_active,
            ]);
        }

        return redirect()->route('vendors.show', $vendor)
            ->with('success', 'Vendor added successfully.');
    }

    public function show(Vendor $vendor)
    {
        $this->authorize('view', $vendor);

        // Count only in-stock items to match what the table displays.
        $vendor->loadCount(['items as items_count' => fn ($q) => $q->where('status', 'in_stock')]);
        $items = $vendor->items()->where('status', 'in_stock')->latest()->take(10)->get();

        return view('vendors.show', compact('vendor', 'items'));
    }

    public function edit(Vendor $vendor)
    {
        $this->authorize('update', $vendor);

        return view('vendors.edit', compact('vendor'));
    }

    public function update(Request $request, Vendor $vendor)
    {
        $this->authorize('update', $vendor);

        // MASTERS PART 3: the old `$data['is_active'] = $request->has('is_active')`
        // line is gone. Lifecycle moves only through archive()/reactivate(), so
        // an edit-form submit (or a crafted one) can no longer flip it.
        $data = $this->validateVendorPayload($request);

        if (isset($data['gst_number'])) {
            $data['gst_number'] = strtoupper(trim($data['gst_number']));
        }

        $vendor->update($data);

        return redirect()->route('vendors.show', $vendor)
            ->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Vendor $vendor)
    {
        $this->authorize('delete', $vendor);

        if ($vendor->items()->exists()) {
            // MASTERS PART 3: deleting would destroy the purchase history those
            // items depend on, so we OFFER archive rather than doing it
            // silently — withdrawing a vendor is the user's call.
            return redirect()->route('vendors.show', $vendor)
                ->with('error', 'This vendor has associated items, so it cannot be deleted. Archive the vendor instead to stop new purchases while keeping all history.');
        }

        $name = $vendor->name;
        $vendor->delete();

        return redirect()->route('vendors.index')
            ->with('success', "Vendor {$name} deleted.");
    }

    /**
     * MASTERS PART 3 — withdraw a vendor from NEW purchases. Every existing
     * item, purchase, bullion lot and payable keeps pointing at it and stays
     * settleable; the vendor simply stops appearing in pickers.
     */
    public function archive(Request $request, Vendor $vendor)
    {
        $this->authorize('delete', $vendor);

        return $this->setPartyActive($request, $vendor, false)
            ? $this->dynamicRedirect('vendors.show', [$vendor], "Vendor {$vendor->name} archived. All history is kept and you can reactivate them anytime.")
            : $this->dynamicRedirect('vendors.show', [$vendor], 'This vendor is already archived.', 'error');
    }

    public function reactivate(Request $request, Vendor $vendor)
    {
        $this->authorize('delete', $vendor);

        return $this->setPartyActive($request, $vendor, true)
            ? $this->dynamicRedirect('vendors.show', [$vendor], "Vendor {$vendor->name} reactivated and available for new purchases.")
            : $this->dynamicRedirect('vendors.show', [$vendor], 'This vendor is already active.', 'error');
    }

    // ─── Helpers ───

    private function validateVendorPayload(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'mobile'         => ['nullable', 'string', 'max:15', 'regex:/^[0-9+\-\s()]{7,15}$/'],
            'email'          => 'nullable|email|max:255',
            'address'        => 'nullable|string|max:1000',
            'city'           => 'nullable|string|max:100',
            'state'          => 'nullable|string|max:100',
            'gst_number'     => [
                'nullable',
                'string',
                'size:15',
                'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i',
            ],
            'notes'          => 'nullable|string|max:2000',
        ], [
            'mobile.regex'      => 'Mobile number must be 7–15 digits and may include +, -, spaces, or parentheses.',
            'gst_number.size'   => 'GST number must be exactly 15 characters.',
            'gst_number.regex'  => 'GST number format is invalid (e.g. 22AAAAA0000A1Z5).',
        ]);
    }
}
