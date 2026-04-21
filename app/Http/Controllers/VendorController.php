<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // Aggregate stats over the full dataset — not the paginated page.
        $stats = Vendor::where('shop_id', $shopId)
            ->selectRaw("
                count(*) as total_count,
                count(*) filter (where is_active IS TRUE) as active_count,
                count(*) filter (where gst_number is not null and gst_number <> '') as gst_count
            ")
            ->first();

        $query = Vendor::where('shop_id', $shopId);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('contact_person', 'ilike', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%")
                  ->orWhere('gst_number', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->inactive();
            }
        }

        $vendors = $query->latest()->paginate(15)->withQueryString();

        return view('vendors.index', compact('vendors', 'stats'));
    }

    public function create()
    {
        return view('vendors.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateVendorPayload($request);
        $data['is_active'] = $data['is_active'] ?? true;

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

        $data = $this->validateVendorPayload($request);
        $data['is_active'] = $request->has('is_active');

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
            return redirect()->route('vendors.show', $vendor)
                ->with('error', 'Cannot delete vendor with associated items.');
        }

        $name = $vendor->name;
        $vendor->delete();

        return redirect()->route('vendors.index')
            ->with('success', "Vendor {$name} deleted.");
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
            'is_active'      => 'boolean',
        ], [
            'mobile.regex'      => 'Mobile number must be 7–15 digits and may include +, -, spaces, or parentheses.',
            'gst_number.size'   => 'GST number must be exactly 15 characters.',
            'gst_number.regex'  => 'GST number format is invalid (e.g. 22AAAAA0000A1Z5).',
        ]);
    }
}
