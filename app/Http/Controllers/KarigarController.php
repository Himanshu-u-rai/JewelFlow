<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ArchivesParties;
use App\Http\Concerns\RespondsDynamically;
use App\Models\Karigar;
use App\Rules\IndianMobileRule;
use Illuminate\Http\Request;

class KarigarController extends Controller
{
    use ArchivesParties, RespondsDynamically;

    public function index(Request $request)
    {
        // MASTERS PART 6: Active is the default view. Disabled karigars are
        // retained (no global scope) and reachable via the status filter, so
        // history and settlement paths are never hidden. 'inactive' aliases
        // 'archived' to keep any existing bookmarks working.
        $status = match ($request->input('status')) {
            'archived', 'inactive' => 'archived',
            'all' => 'all',
            default => 'active',
        };

        $karigars = Karigar::query()
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'archived', fn ($q) => $q->archived())
            ->orderByRaw('is_active DESC')
            ->orderBy('name')
            ->withCount(['jobOrders', 'invoices'])
            ->get();

        // Gold-held total per karigar (reusable leftover + gold out in open jobs).
        // Computed in one pass via the shared breakdown service so the list, the
        // profile, and the job form always agree. Read-only.
        $vault = app(\App\Services\BullionVaultService::class);
        $shopId = auth()->user()->shop_id;
        $goldHeldByKarigar = $karigars->mapWithKeys(fn ($k) => [
            $k->id => (float) $vault->karigarHeldBreakdown($shopId, (int) $k->id)->sum('total'),
        ]);

        // Filter-tab counts over the full dataset, not the filtered page.
        $counts = Karigar::query()
            ->selectRaw('count(*) as all_count, count(*) filter (where is_active IS TRUE) as active_count, count(*) filter (where is_active IS FALSE) as archived_count')
            ->first();

        return view('karigars.index', compact('karigars', 'goldHeldByKarigar', 'status', 'counts'));
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

        // Gold this karigar is holding (reusable leftover + gold out in open jobs),
        // per purity. Read-only — no ledger writes.
        $goldHeld = app(\App\Services\BullionVaultService::class)
            ->karigarHeldBreakdown(auth()->user()->shop_id, (int) $karigar->id);

        return view('karigars.show', compact('karigar', 'goldHeld'));
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

        // MASTERS PART 6: items.karigar_id is nullOnDelete, so deleting a
        // referenced karigar would silently strip the karigar off historical
        // stock. Block it (as job orders / invoices / payments already do) and
        // offer Disable, which keeps every reference intact.
        if ($karigar->items()->exists()
            || $karigar->jobOrders()->exists()
            || $karigar->invoices()->exists()
            || $karigar->payments()->exists()) {
            return back()->with('error', "Cannot delete \"{$karigar->name}\" — there are items, job orders, invoices, or payments linked. Disable instead.");
        }

        $name = $karigar->name;
        $karigar->delete();

        return redirect()->route('karigars.index')
            ->with('success', "Karigar \"{$name}\" deleted.");
    }

    /**
     * MASTERS PART 6 — disable a karigar: withdraw them from NEW items, jobs and
     * commitments while every existing item, job order, invoice, payment and
     * balance keeps pointing at them and stays settleable.
     */
    public function archive(Request $request, Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        return $this->setPartyActive($request, $karigar, false)
            ? $this->dynamicRedirect('karigars.show', [$karigar], "Karigar \"{$karigar->name}\" disabled. All history is kept and you can re-enable them anytime.")
            : $this->dynamicRedirect('karigars.show', [$karigar], 'This karigar is already disabled.', 'error');
    }

    public function reactivate(Request $request, Karigar $karigar)
    {
        $this->authorizeShop($karigar);

        return $this->setPartyActive($request, $karigar, true)
            ? $this->dynamicRedirect('karigars.show', [$karigar], "Karigar \"{$karigar->name}\" re-enabled and available for new work.")
            : $this->dynamicRedirect('karigars.show', [$karigar], 'This karigar is already active.', 'error');
    }

    /**
     * Back-compat shim for the old toggle route. Delegates to the same
     * race-safe, audited, idempotent setPartyActive() as archive/reactivate —
     * no more raw is_active flip. The target is derived from current state; a
     * concurrent change simply no-ops thanks to the locked re-read.
     */
    public function toggle(Karigar $karigar, Request $request)
    {
        $this->authorizeShop($karigar);

        $target = ! $karigar->is_active;
        $this->setPartyActive($request, $karigar, $target);
        $state = $target ? 'enabled' : 'disabled';

        return back()->with('success', "\"{$karigar->name}\" {$state}.");
    }

    private function validateKarigar(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'shop_name' => 'nullable|string|max:150',
            'contact_person' => 'nullable|string|max:150',
            'mobile' => ['nullable', 'string', new IndianMobileRule()],
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
