<?php

namespace App\Http\Controllers;

use App\Models\Karigar;
use Illuminate\Http\Request;

class KarigarController extends Controller
{
    public function index()
    {
        $karigars = Karigar::query()
            ->orderByRaw('is_active DESC')
            ->orderBy('name')
            ->withCount(['jobOrders', 'invoices'])
            ->get();

        return view('karigars.index', compact('karigars'));
    }

    public function create()
    {
        return view('karigars.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateKarigar($request);

        $karigar = Karigar::create(array_merge($validated, [
            'shop_id' => auth()->user()->shop_id,
        ]));

        return redirect()->route('karigars.index')
            ->with('success', "Karigar \"{$karigar->name}\" added.");
    }

    public function show(Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        $karigar->load([
            'jobOrders' => fn ($q) => $q->latest()->limit(20),
            'invoices' => fn ($q) => $q->latest()->limit(20),
            'payments' => fn ($q) => $q->latest()->limit(20),
        ]);

        return view('karigars.show', compact('karigar'));
    }

    public function edit(Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        return view('karigars.edit', compact('karigar'));
    }

    public function update(Request $request, Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        $validated = $this->validateKarigar($request);

        $karigar->update($validated);

        return redirect()->route('karigars.show', $karigar)
            ->with('success', "Karigar \"{$karigar->name}\" updated.");
    }

    public function destroy(Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        if ($karigar->jobOrders()->exists() || $karigar->invoices()->exists()) {
            return back()->with('error', "Cannot delete \"{$karigar->name}\" — there are job orders or invoices linked. Disable instead.");
        }

        $name = $karigar->name;
        $karigar->delete();

        return redirect()->route('karigars.index')
            ->with('success', "Karigar \"{$name}\" deleted.");
    }

    public function toggle(Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        $karigar->update(['is_active' => ! $karigar->is_active]);
        $state = $karigar->is_active ? 'enabled' : 'disabled';

        return back()->with('success', "\"{$karigar->name}\" {$state}.");
    }

    private function validateKarigar(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:150',
            'mobile' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:12',
            'gst_number' => 'nullable|string|max:20',
            'pan_number' => 'nullable|string|max:20',
            'default_wastage_percent' => 'nullable|numeric|min:0|max:50',
            'default_making_per_gram' => 'nullable|numeric|min:0',
            'opening_balance' => 'nullable|numeric',
            'opening_balance_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);
    }

    private function authorizeShop(Karigar $karigar): void
    {
        abort_unless($karigar->shop_id === auth()->user()->shop_id, 403);
    }
}
