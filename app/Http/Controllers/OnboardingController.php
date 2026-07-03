<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Services\InvoiceAccountingService;
use App\Services\MetalRegistry;
use App\Services\OnboardingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Existing-shop onboarding: create/lock/cancel one opening-balance batch.
 *
 * Phase 1 delivers only the orchestration + state machine + atomic lock claim.
 * The ledger-posting body inside lock() fills in over Phases 2–5 (cash, stock,
 * vault, customer/supplier/karigar opening). Locking now just flips state and
 * snapshots — no canonical rows yet, so it is safe to ship incrementally.
 */
class OnboardingController extends Controller
{
    /**
     * Owner-only. Middleware gates the capability; this is defense-in-depth,
     * matching how BulkImportController re-checks ownership in-method.
     */
    private function assertOwner(): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);
    }

    public function index()
    {
        $this->assertOwner();

        $batch = OnboardingBatch::whereNotIn('status', OnboardingBatch::TERMINAL)
            ->latest('id')
            ->first();

        $locked = OnboardingBatch::where('status', OnboardingBatch::STATUS_LOCKED)
            ->latest('locked_at')
            ->get();

        // Reference data + staged rows only matter while a batch is being built.
        $entries = collect();
        $customers = collect();
        $vendors = collect();
        $karigars = collect();
        $metals = [];

        if ($batch) {
            $entries = $batch->entries()->latest('id')->get()->groupBy('kind');
            $shopId = (int) auth()->user()->shop_id;
            $customers = Customer::orderBy('first_name')->get(['id', 'first_name', 'last_name', 'mobile']);
            $vendors = \App\Models\Vendor::orderBy('name')->get(['id', 'name']);
            $karigars = \App\Models\Karigar::orderBy('name')->get(['id', 'name']);
            $metals = MetalRegistry::validationListForShop($shopId);
        }

        return view('onboarding.index', compact('batch', 'locked', 'entries', 'customers', 'vendors', 'karigars', 'metals'));
    }

    public function store(Request $request)
    {
        $this->assertOwner();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
        ]);

        // As-of = day before the shop goes live, so every opening row dated to it
        // classifies as "opening" (created_at < start_date) in every report.
        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $asOf  = $start->copy()->subDay();

        $userId = auth()->id();
        $shopId = (int) auth()->user()->shop_id;

        // App-level friendly guard; the partial unique index is the race backstop.
        if (OnboardingBatch::whereNotIn('status', OnboardingBatch::TERMINAL)->exists()) {
            return back()->withErrors(['start_date' => 'An onboarding batch is already in progress.']);
        }

        $batch = OnboardingBatch::create([
            'shop_id'    => $shopId,
            'as_of_date' => $asOf->toDateString(),
            'start_date' => $start->toDateString(),
            'status'     => OnboardingBatch::STATUS_DRAFT,
            'created_by' => $userId,
        ]);

        AuditLog::create([
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'action'      => 'onboarding_batch_created',
            'model_type'  => 'OnboardingBatch',
            'model_id'    => $batch->id,
            'description' => "Onboarding batch opened (start {$start->toDateString()}, as-of {$asOf->toDateString()})",
        ]);

        return redirect()->route('onboarding.index')->with('success', 'Onboarding batch started.');
    }

    /**
     * Lock: atomic claim draft/review → posting, post opening rows into the
     * canonical ledgers in one transaction, then finalize to locked.
     */
    public function lock(Request $request, OnboardingBatch $onboarding, OnboardingPostingService $posting)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);

        $userId = auth()->id();
        $shopId = (int) auth()->user()->shop_id;

        // The whole claim → post → finalize runs in ONE transaction with a row
        // lock. That is what keeps the batch from ever sticking in "posting": if
        // posting throws, the rollback reverts the status change together with
        // every ledger row, so the batch returns to its prior editable state and
        // can be corrected and retried (or cancelled).
        try {
            $result = DB::transaction(function () use ($onboarding, $userId, $shopId, $posting) {
                // Atomic idempotency claim: a concurrent/duplicate lock blocks on
                // this row lock, then re-reads a non-editable status and no-ops.
                $fresh = OnboardingBatch::whereKey($onboarding->id)->lockForUpdate()->first();
                if (! $fresh || ! $fresh->isEditable()) {
                    return false;
                }

                // Opening rows are back-dated to the as-of date. If the shop has a
                // financial lock covering that date, refuse cleanly — never write
                // ledger rows behind a closed period. The rollback keeps the batch
                // editable and retryable.
                InvoiceAccountingService::assertShopLockForDate($shopId, $fresh->as_of_date->toDateString());

                // Set locked_by first: the posting service reads it to stamp
                // user_id on every posted ledger row.
                $fresh->forceFill([
                    'status'    => OnboardingBatch::STATUS_POSTING,
                    'locked_by' => $userId,
                    'locked_at' => now(),
                ])->save();

                $snapshot = $posting->post($fresh);

                $fresh->forceFill([
                    'status'          => OnboardingBatch::STATUS_LOCKED,
                    'totals_snapshot' => $snapshot,
                ])->save();

                return true;
            });
        } catch (\Throwable $e) {
            // Transaction rolled back: no status change, no partial ledger rows.
            // Audit the failure so a stuck lock attempt is always traceable.
            AuditLog::create([
                'shop_id'     => $shopId,
                'user_id'     => $userId,
                'action'      => 'onboarding_batch_lock_failed',
                'model_type'  => 'OnboardingBatch',
                'model_id'    => $onboarding->id,
                'description' => 'Onboarding lock failed: ' . $e->getMessage(),
            ]);

            return back()->withErrors(['lock' => 'Locking failed — no opening rows were saved. ' . $e->getMessage() . ' Edit and try again.']);
        }

        if ($result === false) {
            return back()->withErrors(['lock' => 'This batch can no longer be locked.']);
        }

        AuditLog::create([
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'action'      => 'onboarding_batch_locked',
            'model_type'  => 'OnboardingBatch',
            'model_id'    => $onboarding->id,
            'description' => 'Onboarding batch locked',
        ]);

        return redirect()->route('onboarding.index')->with('success', 'Onboarding batch locked.');
    }

    public function cancel(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);

        abort_unless($onboarding->isEditable(), 422, 'Only a draft or review batch can be cancelled.');

        $userId = auth()->id();
        $shopId = (int) auth()->user()->shop_id;

        $onboarding->update(['status' => OnboardingBatch::STATUS_CANCELLED]);

        AuditLog::create([
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'action'      => 'onboarding_batch_cancelled',
            'model_type'  => 'OnboardingBatch',
            'model_id'    => $onboarding->id,
            'description' => 'Onboarding batch cancelled',
        ]);

        return redirect()->route('onboarding.index')->with('success', 'Onboarding batch cancelled.');
    }

    /**
     * Minimal supplier opening-outstanding view. Ongoing supplier ledger is out
     * of scope; this lists only what was seeded at onboarding, netted per vendor.
     */
    public function suppliers()
    {
        $this->assertOwner();

        $rows = \App\Models\SupplierOpeningBalance::query()
            ->selectRaw('vendor_id, COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) as net_payable', [\App\Models\SupplierOpeningBalance::DIRECTION_PAYABLE])
            ->groupBy('vendor_id')
            ->with('vendor')
            ->get();

        return view('onboarding.suppliers', compact('rows'));
    }

    /**
     * Stage one opening entry under an editable batch. The validated payload is
     * stored as-is; OnboardingPostingService posts it to the canonical ledger at
     * lock. Nothing hits a real ledger here — staging is fully editable.
     */
    public function addEntry(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $shopId = (int) auth()->user()->shop_id;
        $kind   = (string) $request->input('kind');
        abort_unless(in_array($kind, OnboardingEntry::KINDS, true), 422, 'Unknown opening kind.');

        $payload = $request->validate($this->entryRules($kind, $shopId, (string) $request->input('metal_type', '')));

        OnboardingEntry::create([
            'shop_id'             => $shopId,
            'onboarding_batch_id' => $onboarding->id,
            'kind'                => $kind,
            'payload'             => $payload,
            'created_by'          => auth()->id(),
        ]);

        return back()->with('success', 'Opening entry added.');
    }

    public function deleteEntry(OnboardingBatch $onboarding, OnboardingEntry $entry)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($entry->onboarding_batch_id !== $onboarding->id, 404);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $entry->delete();

        return back()->with('success', 'Opening entry removed.');
    }

    /**
     * Bulk-add customers from CSV. Customers carry no financial value, so they
     * are created directly in the directory (not staged) and deduped by mobile
     * within the shop. Header row required: first_name,last_name,mobile,email,address.
     */
    public function importCustomers(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue; // blank line
            }
            $data   = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $mobile = trim((string) ($data['mobile'] ?? ''));

            // Dedupe by mobile within the shop; nameless/numberless rows skipped.
            if ($mobile === '' || Customer::where('mobile', $mobile)->exists()) {
                $skipped++;
                continue;
            }

            Customer::create([
                'first_name' => trim((string) ($data['first_name'] ?? '')) ?: 'Customer',
                'last_name'  => trim((string) ($data['last_name'] ?? '')) ?: null,
                'mobile'     => $mobile,
                'email'      => trim((string) ($data['email'] ?? '')) ?: null,
                'address'    => trim((string) ($data['address'] ?? '')) ?: null,
            ]);
            $created++;
        }
        fclose($handle);

        return back()->with('success', "Imported {$created} customer(s); skipped {$skipped}.");
    }

    /**
     * Per-kind payload validation. Shop-scoped exists() rules stop a staged
     * entry referencing another tenant's customer/vendor/karigar; metal is
     * limited to the shop's enabled metals.
     */
    private function entryRules(string $kind, int $shopId, string $metalType = ''): array
    {
        $metals   = MetalRegistry::validationListForShop($shopId);
        $customer = Rule::exists('customers', 'id')->where('shop_id', $shopId);
        $vendor   = Rule::exists('vendors', 'id')->where('shop_id', $shopId);
        $karigar  = Rule::exists('karigars', 'id')->where('shop_id', $shopId);

        // Vault/karigar metal must be an accounting-truth metal (gold/silver): only
        // those carry a fine-weight-bearing purity that the vault/reconciliation
        // pools understand. Platinum/copper are piece-priced and out of scope here.
        $accountingMetals = array_values(array_intersect($metals, MetalRegistry::accountingTruthMetals()));

        // Per-metal purity cap = the system's existing authority (gold 24, else
        // 999). 999 is also the ceiling of metal_lots.purity / items.purity
        // decimal(5,2), so this doubles as an overflow guard.
        $purityMax = \App\Models\Repair::maxPurityFor($metalType);

        return match ($kind) {
            OnboardingEntry::KIND_CASH => [
                'payment_mode' => ['required', Rule::in(['cash', 'bank', 'upi', 'card', 'wallet'])],
                'amount'       => ['required', 'numeric', 'gt:0'],
            ],
            OnboardingEntry::KIND_VAULT_METAL => [
                'metal_type'         => ['required', Rule::in($accountingMetals)],
                'purity'             => ['required', 'numeric', 'gt:0', 'max:' . $purityMax],
                'fine_weight'        => ['required', 'numeric', 'gt:0'],
                'cost_per_fine_gram' => ['nullable', 'numeric', 'gte:0'],
            ],
            OnboardingEntry::KIND_KARIGAR_GOLD => [
                'karigar_id'         => ['required', $karigar],
                'metal_type'         => ['required', Rule::in($accountingMetals)],
                'purity'             => ['required', 'numeric', 'gt:0', 'max:' . $purityMax],
                'fine_weight'        => ['required', 'numeric', 'gt:0'],
                'cost_per_fine_gram' => ['nullable', 'numeric', 'gte:0'],
            ],
            OnboardingEntry::KIND_KARIGAR_MONEY => [
                'karigar_id' => ['required', $karigar],
                'amount'     => ['required', 'numeric', 'not_in:0'],
            ],
            OnboardingEntry::KIND_CUSTOMER_GOLD => [
                'customer_id'  => ['required', $customer],
                'fine_gold'    => ['required', 'numeric', 'not_in:0'],
                'gross_weight' => ['nullable', 'numeric', 'gte:0'],
                'purity'       => ['nullable', 'numeric', 'gte:0'],
            ],
            OnboardingEntry::KIND_CUSTOMER_ADVANCE => [
                'customer_id' => ['required', $customer],
                'amount'      => ['required', 'numeric', 'gt:0'],
            ],
            OnboardingEntry::KIND_CUSTOMER_RECEIVABLE,
            OnboardingEntry::KIND_CUSTOMER_PAYABLE => [
                'customer_id' => ['required', $customer],
                'amount'      => ['required', 'numeric', 'gt:0'],
                'notes'       => ['nullable', 'string', 'max:500'],
            ],
            OnboardingEntry::KIND_SUPPLIER_PAYABLE,
            OnboardingEntry::KIND_SUPPLIER_RECEIVABLE => [
                'vendor_id' => ['required', $vendor],
                'amount'    => ['required', 'numeric', 'gt:0'],
                'notes'     => ['nullable', 'string', 'max:500'],
            ],
            OnboardingEntry::KIND_STOCK_ITEM => [
                'metal_type'     => ['required', Rule::in($metals)],
                'gross_weight'   => ['required', 'numeric', 'gt:0'],
                // stone must fit within gross, else net_metal_weight (gross-stone)
                // goes negative and poisons the opening-stock fine-weight snapshot.
                'stone_weight'   => ['nullable', 'numeric', 'gte:0', 'lte:gross_weight'],
                // Same per-metal cap as vault/karigar — guards items.purity decimal(5,2).
                'purity'         => ['required', 'numeric', 'gt:0', 'max:' . $purityMax],
                'making_charges' => ['nullable', 'numeric', 'gte:0'],
                'stone_charges'  => ['nullable', 'numeric', 'gte:0'],
                'cost_price'     => ['nullable', 'numeric', 'gte:0'],
                'selling_price'  => ['nullable', 'numeric', 'gte:0'],
                'barcode'        => ['nullable', 'string', 'max:100'],
                'design'         => ['nullable', 'string', 'max:255'],
                'category'       => ['nullable', 'string', 'max:100'],
                'sub_category'   => ['nullable', 'string', 'max:100'],
                'huid'           => ['nullable', 'string', 'max:50'],
            ],
            default => [],
        };
    }
}
