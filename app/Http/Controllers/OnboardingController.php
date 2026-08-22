<?php

namespace App\Http\Controllers;

use App\Support\Csv;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\ShopPaymentMethod;
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
     * One-step-per-page wizard order (?step=). Missing/unknown → first step.
     */
    private const WIZARD_STEPS = ['customers', 'cash', 'stock', 'vault', 'balances', 'suppliers', 'review'];

    /**
     * Owner-only. Middleware gates the capability; this is defense-in-depth,
     * matching how BulkImportController re-checks ownership in-method.
     */
    private function assertOwner(): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);
    }

    public function index(Request $request)
    {
        $this->assertOwner();

        $shopId = (int) auth()->user()->shop_id;

        // An editable/posting batch takes precedence — it's a migration in flight.
        $batch = OnboardingBatch::whereNotIn('status', OnboardingBatch::TERMINAL)
            ->latest('id')
            ->first();

        $locked = OnboardingBatch::where('status', OnboardingBatch::STATUS_LOCKED)
            ->latest('locked_at')
            ->get();
        $lockedLatest = $locked->first();

        $latest = OnboardingBatch::latest('id')->first();
        $prefs  = \App\Models\ShopPreferences::where('shop_id', $shopId)->first();
        $startedClean = (bool) ($prefs?->opening_setup_skipped_at);

        // Front-screen state machine. Batch existence wins over the clean flag, so
        // an owner who started clean can still choose to migrate later.
        if ($batch) {
            $state = 'resume';
        } elseif ($lockedLatest) {
            $state = 'locked';
        } elseif ($request->query('step') === 'migrate') {
            $state = 'migrate';
        } elseif ($startedClean) {
            $state = 'clean';
        } else {
            $state = 'landing';
        }

        // Warn (never block) if the shop already has live sales — opening balances
        // are for pre-JewelFlow data only.
        $hasLiveSales = \App\Models\Invoice::where('status', \App\Models\Invoice::STATUS_FINALIZED)->exists();

        // Show a one-time note if the previous batch was cancelled.
        $cancelledNotice = $state === 'landing'
            && $latest
            && $latest->status === OnboardingBatch::STATUS_CANCELLED;

        // Reference data + staged rows only matter while a batch is being built.
        $entries = collect();
        $customers = collect();
        $vendors = collect();
        $karigars = collect();
        $paymentMethods = collect();
        $purityProfiles = collect();
        $metals = [];
        $accountingMetals = [];

        if ($batch) {
            $entries = $batch->entries()->latest('id')->get()->groupBy('kind');
            $customers = Customer::orderBy('first_name')->get(['id', 'first_name', 'last_name', 'mobile', 'email', 'address']);
            $vendors = \App\Models\Vendor::orderBy('name')->get(['id', 'name']);
            $karigars = \App\Models\Karigar::orderBy('name')->get(['id', 'name']);
            $paymentMethods = ShopPaymentMethod::orderBy('sort_order')->get()->groupBy('type');
            $purityProfiles = app(\App\Services\ShopPricingService::class)
                ->activePurityProfiles($shopId)
                ->sortBy('sort_order')
                ->groupBy('metal_type');
            $metals = MetalRegistry::validationListForShop($shopId);
            // Karigar-held gold / vault bullion accept only accounting-truth metals
            // (matches entryRules); expose the narrowed set to the wizard dropdowns.
            $accountingMetals = array_values(array_intersect($metals, MetalRegistry::accountingTruthMetals()));
        }

        // Wizard is one step per page while a batch is being built. The step is
        // URL-driven (?step=), so resume returns wherever the owner navigated —
        // no schema/progress column needed. Unknown/missing step → the first one.
        $step = null;
        if ($state === 'resume' && $batch->isEditable()) {
            $step = in_array($request->query('step'), self::WIZARD_STEPS, true)
                ? $request->query('step')
                : self::WIZARD_STEPS[0];
        }

        return view('onboarding.index', compact(
            'state', 'batch', 'locked', 'lockedLatest', 'startedClean',
            'cancelledNotice', 'hasLiveSales', 'step',
            'entries', 'customers', 'vendors', 'karigars', 'paymentMethods', 'purityProfiles', 'metals', 'accountingMetals'
        ));
    }

    /**
     * Owner records "Start Clean" — the shop begins fresh with no opening
     * balances. Stores a flag so the dashboard prompt stops nagging. Does not
     * touch any data or block normal app usage; the owner can still migrate later.
     */
    public function startClean(Request $request)
    {
        $this->assertOwner();

        $shopId = (int) auth()->user()->shop_id;

        // Never override a migration already in flight or completed.
        if (OnboardingBatch::whereNotIn('status', OnboardingBatch::TERMINAL)->exists()
            || OnboardingBatch::where('status', OnboardingBatch::STATUS_LOCKED)->exists()) {
            return back()->withErrors(['start' => 'An onboarding batch already exists.']);
        }

        \App\Models\ShopPreferences::updateOrCreate(
            ['shop_id' => $shopId],
            ['opening_setup_skipped_at' => now()]
        );

        AuditLog::create([
            'shop_id'     => $shopId,
            'user_id'     => auth()->id(),
            'action'      => 'onboarding_started_clean',
            'model_type'  => 'ShopPreferences',
            'model_id'    => $shopId,
            'description' => 'Owner chose to start clean (no opening balances).',
        ]);

        return redirect()->route('onboarding.index')->with('success', 'Starting clean — no opening balances to enter.');
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

        // Choosing to migrate supersedes any prior "start clean" choice.
        \App\Models\ShopPreferences::where('shop_id', $shopId)
            ->update(['opening_setup_skipped_at' => null]);

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

        // Nothing to post → keep the batch editable, don't burn the lock.
        if (! $onboarding->entries()->exists()) {
            return back()->withErrors(['lock' => 'Add at least one opening balance before locking.']);
        }

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

        $header = Csv::get($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $skipped = 0;
        while (($row = Csv::get($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue; // blank line
            }
            $data   = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            // Canonical form (see Customer::storableMobile) for both the dedupe
            // and the insert: an exported CSV spells numbers however the shop's
            // old system did, so '+91 98123 00099' would otherwise import as a
            // second row for a buyer who is already in the directory.
            $mobile = Customer::storableMobile($data['mobile'] ?? null);

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
     * Add one customer manually from the Step 2 form. Same directory + dedupe
     * rule as the CSV import; a duplicate mobile is a hard error so the owner
     * notices rather than silently losing the row.
     */
    public function storeCustomer(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'mobile'     => ['required', 'string', 'max:20'],
            'email'      => ['nullable', 'email', 'max:150'],
            'address'    => ['nullable', 'string', 'max:500'],
        ]);

        // `mobile` here is max:20 free text, not digits:10 like the customer
        // form — canonicalise before the duplicate check and the insert so the
        // same buyer cannot enter the directory twice under two spellings.
        $data['mobile'] = Customer::storableMobile($data['mobile']);

        if (Customer::where('mobile', $data['mobile'])->exists()) {
            return back()->withErrors(['mobile' => 'A customer with this mobile already exists.'])->withInput();
        }

        Customer::create($data);

        return back()->with('success', 'Customer added.');
    }

    /**
     * Create a supplier (vendor) directly in the directory from the Suppliers step.
     * Deduped by name within the shop (name is the vendor's natural key + how the
     * opening-balance CSV resolves them).
     */
    public function storeVendor(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'mobile'         => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:150'],
            'address'        => ['nullable', 'string', 'max:500'],
            'gst_number'     => ['nullable', 'string', 'max:20'],
        ]);

        if (\App\Models\Vendor::where('name', $data['name'])->exists()) {
            return back()->withErrors(['name' => 'A supplier with this name already exists.'])->withInput();
        }

        \App\Models\Vendor::create($data);

        return back()->with('success', 'Supplier added.');
    }

    /**
     * Create a karigar directly in the directory from the Suppliers step.
     * Deduped by name within the shop (how the opening-balance CSV resolves them).
     */
    public function storeKarigar(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'shop_name'      => ['nullable', 'string', 'max:150'],
            'mobile'         => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:150'],
        ]);

        if (\App\Models\Karigar::where('name', $data['name'])->exists()) {
            return back()->withErrors(['name' => 'A karigar with this name already exists.'])->withInput();
        }

        \App\Models\Karigar::create($data);

        return back()->with('success', 'Karigar added.');
    }

    /**
     * Edit one customer inline from the Step 2 table. Same fields + dedupe as
     * add (mobile must stay unique, excluding this row). Redirects back to the
     * wizard, unlike the full customers.update which returns to the directory.
     */
    public function updateCustomer(Request $request, OnboardingBatch $onboarding, Customer $customer)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($customer->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'mobile'     => ['required', 'string', 'max:20'],
            'email'      => ['nullable', 'email', 'max:150'],
            'address'    => ['nullable', 'string', 'max:500'],
        ]);

        if (Customer::where('mobile', $data['mobile'])->where('id', '!=', $customer->id)->exists()) {
            return back()->withErrors(['mobile' => 'Another customer already uses this mobile.'])->withInput();
        }

        $customer->update($data);

        return back()->with('success', 'Customer updated.');
    }

    /**
     * Delete one customer from the Step 2 table. Blocks deletion if the customer
     * is referenced by a staged opening balance (would orphan the entry) or by
     * real history (invoices / gold / repairs) — same history guard as
     * CustomerController::destroy, but staying inside the wizard.
     */
    public function destroyCustomer(OnboardingBatch $onboarding, Customer $customer)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($customer->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $stagedForCustomer = $onboarding->entries()
            ->get()
            ->contains(fn ($e) => (int) ($e->payload['customer_id'] ?? 0) === $customer->id);

        if ($stagedForCustomer) {
            return back()->withErrors(['customer' => 'This customer has staged opening balances. Remove those first (Customer balances step).']);
        }

        if (\App\Models\Invoice::where('customer_id', $customer->id)->exists()
            || \App\Models\CustomerGoldTransaction::where('customer_id', $customer->id)->exists()
            || $customer->repairs()->exists()) {
            return back()->withErrors(['customer' => 'Cannot delete a customer with invoices, gold transactions, or repairs.']);
        }

        $customer->delete();

        return back()->with('success', 'Customer removed.');
    }

    /**
     * Sample CSV so the owner can build a valid customer import file. Mirrors
     * BulkImportController::downloadTemplate — header row + two example rows,
     * values with commas quoted.
     */
    public function customerTemplate()
    {
        $this->assertOwner();

        $headers = ['first_name', 'last_name', 'mobile', 'email', 'address'];
        $samples = [
            ['Ramesh', 'Kumar', '9876543210', 'ramesh@example.com', '12 MG Road, Jaipur'],
            ['Sita', 'Devi', '9812345678', '', 'Near Temple, Udaipur'],
        ];

        $lines = [implode(',', $headers)];
        foreach ($samples as $s) {
            $lines[] = implode(',', array_map(
                fn ($v) => str_contains((string) $v, ',') ? '"' . $v . '"' : (string) $v,
                $s
            ));
        }

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-customers-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Stage one opening cash/bank balance from the Step 3 form. `source` is
     * either 'cash' (drawer) or a ShopPaymentMethod id (bank/upi/wallet). The
     * ledger only stores payment_mode, so the specific account is resolved
     * server-side to its mode + a display label kept in the staged payload (the
     * label also enriches the posted ledger description). Accounts are created
     * via the real Settings route, so they appear in Settings too.
     */
    public function storeCashOpening(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'source' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        OnboardingEntry::create([
            'shop_id'             => (int) auth()->user()->shop_id,
            'onboarding_batch_id' => $onboarding->id,
            'kind'                => OnboardingEntry::KIND_CASH,
            'payload'             => array_merge($this->resolveCashSource($data['source']), [
                'amount' => round((float) $data['amount'], 2),
            ]),
            'created_by'          => auth()->id(),
        ]);

        return back()->with('success', 'Opening balance added.');
    }

    public function updateCashOpening(Request $request, OnboardingBatch $onboarding, OnboardingEntry $entry)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($entry->onboarding_batch_id !== $onboarding->id, 404);
        abort_if($entry->kind !== OnboardingEntry::KIND_CASH, 404);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $data = $request->validate([
            'source' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $entry->update([
            'payload' => array_merge($this->resolveCashSource($data['source']), [
                'amount' => round((float) $data['amount'], 2),
            ]),
        ]);

        return back()->with('success', 'Opening balance updated.');
    }

    /**
     * Edit a staged finished-stock item. Same entryRules as the add form.
     */
    public function updateStock(Request $request, OnboardingBatch $onboarding, OnboardingEntry $entry)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($entry->onboarding_batch_id !== $onboarding->id, 404);
        abort_if($entry->kind !== OnboardingEntry::KIND_STOCK_ITEM, 404);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $shopId  = (int) auth()->user()->shop_id;
        $payload = $request->validate(
            $this->entryRules(OnboardingEntry::KIND_STOCK_ITEM, $shopId, (string) $request->input('metal_type', ''))
        );

        $entry->update(['payload' => $payload]);

        return back()->with('success', 'Opening item updated.');
    }

    /**
     * Edit a staged vault bullion lot. Same entryRules as the add form.
     */
    public function updateVault(Request $request, OnboardingBatch $onboarding, OnboardingEntry $entry)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($entry->onboarding_batch_id !== $onboarding->id, 404);
        abort_if($entry->kind !== OnboardingEntry::KIND_VAULT_METAL, 404);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $shopId  = (int) auth()->user()->shop_id;
        $payload = $request->validate(
            $this->entryRules(OnboardingEntry::KIND_VAULT_METAL, $shopId, (string) $request->input('metal_type', ''))
        );

        $entry->update(['payload' => $payload]);

        return back()->with('success', 'Vault lot updated.');
    }

    /**
     * Edit a staged customer opening balance (receivable/payable/advance/gold).
     * Validates against the same entryRules keyed by the entry's own kind.
     */
    public function updateBalance(Request $request, OnboardingBatch $onboarding, OnboardingEntry $entry)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_if($entry->onboarding_batch_id !== $onboarding->id, 404);
        abort_unless(in_array($entry->kind, [
            OnboardingEntry::KIND_CUSTOMER_RECEIVABLE,
            OnboardingEntry::KIND_CUSTOMER_PAYABLE,
            OnboardingEntry::KIND_CUSTOMER_ADVANCE,
            OnboardingEntry::KIND_CUSTOMER_GOLD,
        ], true), 404);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $shopId  = (int) auth()->user()->shop_id;
        $payload = $request->validate($this->entryRules($entry->kind, $shopId));

        $entry->update(['payload' => $payload]);

        return back()->with('success', 'Customer balance updated.');
    }

    /**
     * Bulk-stage opening cash rows from CSV. Header: payment_mode,amount.
     * payment_mode is a generic mode (cash/bank/upi/wallet) — no specific
     * account link (use the form for that). Mirrors importCustomers.
     */
    public function importCash(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = Csv::get($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $modes   = ['cash', 'bank', 'upi', 'wallet'];
        $created = 0;
        $skipped = 0;
        while (($row = Csv::get($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $data   = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $mode   = strtolower(trim((string) ($data['payment_mode'] ?? '')));
            $amount = round((float) ($data['amount'] ?? 0), 2);

            if (! in_array($mode, $modes, true) || $amount <= 0) {
                $skipped++;
                continue;
            }

            OnboardingEntry::create([
                'shop_id'             => (int) auth()->user()->shop_id,
                'onboarding_batch_id' => $onboarding->id,
                'kind'                => OnboardingEntry::KIND_CASH,
                'payload'             => [
                    'payment_mode'      => $mode,
                    'account_label'     => $mode === 'cash' ? 'Cash in drawer' : ucfirst($mode),
                    'payment_method_id' => null,
                    'amount'            => $amount,
                ],
                'created_by'          => auth()->id(),
            ]);
            $created++;
        }
        fclose($handle);

        return back()->with('success', "Imported {$created} balance(s); skipped {$skipped}.");
    }

    public function cashTemplate()
    {
        $this->assertOwner();

        $csv = "payment_mode,amount\ncash,50000\nbank,120000\nupi,8000\nwallet,1500\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-cash-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Bulk-stage opening finished-stock items from CSV. Each valid row is staged
     * as a KIND_STOCK_ITEM entry (posted to items at lock). Rows failing the same
     * entryRules used by the manual form are skipped, not fatal (row mode).
     * Header: metal_type,gross_weight,stone_weight,purity,making_charges,stone_charges,cost_price,selling_price,barcode,design,category,sub_category,huid.
     */
    public function importStock(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = Csv::get($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $shopId  = (int) auth()->user()->shop_id;
        $fields  = ['metal_type', 'gross_weight', 'stone_weight', 'purity', 'making_charges', 'stone_charges', 'cost_price', 'selling_price', 'barcode', 'design', 'category', 'sub_category', 'huid', 'hallmark_date'];
        $created = 0;
        $skipped = 0;
        while (($row = Csv::get($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $raw  = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $data = [];
            foreach ($fields as $f) {
                $val = isset($raw[$f]) ? trim((string) $raw[$f]) : '';
                $data[$f] = $val === '' ? null : $val;
            }

            $rules     = $this->entryRules(OnboardingEntry::KIND_STOCK_ITEM, $shopId, (string) ($data['metal_type'] ?? ''));
            $validator = \Illuminate\Support\Facades\Validator::make($data, $rules);
            if ($validator->fails()) {
                $skipped++;
                continue;
            }

            OnboardingEntry::create([
                'shop_id'             => $shopId,
                'onboarding_batch_id' => $onboarding->id,
                'kind'                => OnboardingEntry::KIND_STOCK_ITEM,
                'payload'             => $validator->validated(),
                'created_by'          => auth()->id(),
            ]);
            $created++;
        }
        fclose($handle);

        return back()->with('success', "Imported {$created} item(s); skipped {$skipped}.");
    }

    public function stockTemplate()
    {
        $this->assertOwner();

        $csv = "metal_type,gross_weight,stone_weight,purity,making_charges,stone_charges,cost_price,selling_price,barcode,design,category,sub_category,huid,hallmark_date\n"
            . "gold,10.500,0.500,22,1200,0,45000,52000,BC1001,Antique Ring,Rings,Ladies,HUID123456,2025-06-15\n"
            . "silver,25.000,0,999,300,0,2000,2600,BC1002,Payal,Anklets,Ladies,,\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-stock-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Shared CSV loop for the staging importers. Reads the header, streams each
     * non-empty row (as a lowercased-key assoc array) to $onRow, which returns
     * true when it stages a row and false when it skips one. Redirects back with
     * an "Imported X {noun}; skipped Y." summary. Owner/batch guards stay in the
     * caller (they differ per route).
     */
    private function importCsv(Request $request, string $noun, \Closure $onRow)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = Csv::get($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $skipped = 0;
        while (($row = Csv::get($handle)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $assoc = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $assoc = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $assoc);
            $onRow($assoc) ? $created++ : $skipped++;
        }
        fclose($handle);

        return back()->with('success', "Imported {$created} {$noun}; skipped {$skipped}.");
    }

    /**
     * Stage one CSV row as an OnboardingEntry after validating $payload against
     * the same entryRules the manual form uses. Returns false (skip) on failure.
     */
    private function stageValidatedRow(int $shopId, OnboardingBatch $onboarding, string $kind, array $payload, string $metalType = ''): bool
    {
        $validator = \Illuminate\Support\Facades\Validator::make(
            $payload,
            $this->entryRules($kind, $shopId, $metalType)
        );
        if ($validator->fails()) {
            return false;
        }

        OnboardingEntry::create([
            'shop_id'             => $shopId,
            'onboarding_batch_id' => $onboarding->id,
            'kind'                => $kind,
            'payload'             => $validator->validated(),
            'created_by'          => auth()->id(),
        ]);

        return true;
    }

    /**
     * Vault CSV. Header: metal_type,purity,fine_weight,cost_per_fine_gram.
     */
    public function importVault(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');
        $shopId = (int) auth()->user()->shop_id;

        return $this->importCsv($request, 'lot(s)', function (array $r) use ($shopId, $onboarding) {
            return $this->stageValidatedRow($shopId, $onboarding, OnboardingEntry::KIND_VAULT_METAL, [
                'metal_type'         => $r['metal_type'] ?? null,
                'purity'             => $r['purity'] ?? null,
                'fine_weight'        => $r['fine_weight'] ?? null,
                'cost_per_fine_gram' => $r['cost_per_fine_gram'] ?? null,
                'notes'              => $r['notes'] ?? null,
            ], (string) ($r['metal_type'] ?? ''));
        });
    }

    public function vaultTemplate()
    {
        $this->assertOwner();
        $csv = "metal_type,purity,fine_weight,cost_per_fine_gram,notes\ngold,24,50.000,6800,Pure gold bar\nsilver,999,1000.000,90,Silver bar\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-vault-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Customer balances CSV. Header: mobile,type,amount. type =
     * receivable|payable|advance|gold (gold → amount is fine grams). Customer must
     * already exist (resolved by mobile within the shop); unknown mobile → skip.
     */
    public function importBalances(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');
        $shopId = (int) auth()->user()->shop_id;

        $kinds = [
            'receivable' => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE,
            'payable'    => OnboardingEntry::KIND_CUSTOMER_PAYABLE,
            'advance'    => OnboardingEntry::KIND_CUSTOMER_ADVANCE,
            'gold'       => OnboardingEntry::KIND_CUSTOMER_GOLD,
        ];

        return $this->importCsv($request, 'balance(s)', function (array $r) use ($shopId, $onboarding, $kinds) {
            $type = strtolower(trim((string) ($r['type'] ?? '')));
            if (! isset($kinds[$type])) {
                return false;
            }
            $customer = Customer::where('mobile', trim((string) ($r['mobile'] ?? '')))->first();
            if (! $customer) {
                return false;
            }

            $payload = $type === 'gold'
                ? ['customer_id' => $customer->id, 'fine_gold' => $r['amount'] ?? null]
                : ['customer_id' => $customer->id, 'amount' => $r['amount'] ?? null];

            return $this->stageValidatedRow($shopId, $onboarding, $kinds[$type], $payload);
        });
    }

    public function balancesTemplate()
    {
        $this->assertOwner();
        $csv = "mobile,type,amount\n9876543210,receivable,15000\n9876500000,advance,5000\n9876511111,gold,12.500\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-customer-balances-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Suppliers & karigars CSV. Header:
     * type,name,amount,metal_type,purity,fine_weight. type =
     * supplier_payable|supplier_receivable|karigar_money|karigar_gold. name
     * resolves against vendors (supplier_*) or karigars (karigar_*); metal columns
     * apply only to karigar_gold. Unknown type or unresolved name → skip.
     */
    public function importSuppliers(Request $request, OnboardingBatch $onboarding)
    {
        $this->assertOwner();
        abort_if($onboarding->shop_id !== auth()->user()->shop_id, 403);
        abort_unless($onboarding->isEditable(), 422, 'This batch is locked.');
        $shopId = (int) auth()->user()->shop_id;

        return $this->importCsv($request, 'row(s)', function (array $r) use ($shopId, $onboarding) {
            $type = strtolower(trim((string) ($r['type'] ?? '')));
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                return false;
            }

            if ($type === 'supplier_payable' || $type === 'supplier_receivable') {
                $vendor = \App\Models\Vendor::where('name', $name)->first();
                if (! $vendor) {
                    return false;
                }
                $kind = $type === 'supplier_payable'
                    ? OnboardingEntry::KIND_SUPPLIER_PAYABLE
                    : OnboardingEntry::KIND_SUPPLIER_RECEIVABLE;

                return $this->stageValidatedRow($shopId, $onboarding, $kind, [
                    'vendor_id' => $vendor->id,
                    'amount'    => $r['amount'] ?? null,
                ]);
            }

            if ($type === 'karigar_money' || $type === 'karigar_gold') {
                $karigar = \App\Models\Karigar::where('name', $name)->first();
                if (! $karigar) {
                    return false;
                }
                if ($type === 'karigar_money') {
                    return $this->stageValidatedRow($shopId, $onboarding, OnboardingEntry::KIND_KARIGAR_MONEY, [
                        'karigar_id' => $karigar->id,
                        'amount'     => $r['amount'] ?? null,
                    ]);
                }

                return $this->stageValidatedRow($shopId, $onboarding, OnboardingEntry::KIND_KARIGAR_GOLD, [
                    'karigar_id'  => $karigar->id,
                    'metal_type'  => $r['metal_type'] ?? null,
                    'purity'      => $r['purity'] ?? null,
                    'fine_weight' => $r['fine_weight'] ?? null,
                ], (string) ($r['metal_type'] ?? ''));
            }

            return false;
        });
    }

    public function suppliersTemplate()
    {
        $this->assertOwner();
        $csv = "type,name,amount,metal_type,purity,fine_weight\n"
            . "supplier_payable,Acme Bullion,250000,,,\n"
            . "supplier_receivable,Star Gems,12000,,,\n"
            . "karigar_money,Ramesh Soni,8000,,,\n"
            . "karigar_gold,Ramesh Soni,,gold,22,45.000\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-suppliers-template.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /**
     * Resolve a Step 3 `source` (either 'cash' or a ShopPaymentMethod id) to the
     * ledger payment_mode + a display label + the linked method id. The
     * ShopPaymentMethod lookup is shop-scoped by BelongsToShop.
     */
    private function resolveCashSource(string $source): array
    {
        if ($source === 'cash') {
            return ['payment_mode' => 'cash', 'account_label' => 'Cash in drawer', 'payment_method_id' => null];
        }

        $method = ShopPaymentMethod::find($source);
        abort_unless($method, 422, 'Unknown payment account.');

        return [
            'payment_mode'      => $method->type,
            'account_label'     => $method->account_label,
            'payment_method_id' => $method->id,
        ];
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
                'notes'              => ['nullable', 'string', 'max:255'],
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
                // huid column is string(30) + unique(shop_id,huid): reject an
                // over-length or already-taken code here, not at lock (a dup would
                // otherwise crash the whole batch post inside its transaction).
                'huid'           => ['nullable', 'string', 'max:30', Rule::unique('items', 'huid')->where('shop_id', $shopId)],
                'hallmark_date'  => ['nullable', 'date'],
            ],
            default => [],
        };
    }
}
