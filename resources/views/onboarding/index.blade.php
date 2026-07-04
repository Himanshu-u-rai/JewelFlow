<x-app-layout>
    {{-- ponytail: single-page wizard. Every opening "step" is a section with an
         add-form; staged rows post into canonical ledgers only at Lock. The front
         screen is a small state machine: landing → migrate → resume → locked. --}}
    @php
        // ponytail: class-name vars — `use` imports are illegal inside @php (it
        // compiles into a function body). $Var::CONST is valid PHP.
        $OE = \App\Models\OnboardingEntry::class;
        $MR = \App\Services\MetalRegistry::class;

        $customerLabel = fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) . ($c->mobile ? " ({$c->mobile})" : '');

        // Review subtotals from staged rows — raw staged data, so no drift.
        $sum = fn ($kind, $field) => ($entries[$kind] ?? collect())->sum(fn ($e) => (float) ($e->payload[$field] ?? 0));
        $cashByMode = ($entries[$OE::KIND_CASH] ?? collect())
            ->groupBy(fn ($e) => $e->payload['payment_mode'] ?? 'cash')
            ->map(fn ($g) => $g->sum(fn ($e) => (float) ($e->payload['amount'] ?? 0)));
        $stockFine = ($entries[$OE::KIND_STOCK_ITEM] ?? collect())->sum(function ($e) use ($MR) {
            $p = $e->payload;
            $net = (float) ($p['gross_weight'] ?? 0) - (float) ($p['stone_weight'] ?? 0);
            $m = $MR::fineWeightMultiplier((string) ($p['metal_type'] ?? ''), (float) ($p['purity'] ?? 0));
            return $m ? $net * $m : 0;
        });
        $vaultFine = $sum($OE::KIND_VAULT_METAL, 'fine_weight');
        $karigarHeldFine = $sum($OE::KIND_KARIGAR_GOLD, 'fine_weight');
        $customerGoldFine = $sum($OE::KIND_CUSTOMER_GOLD, 'fine_gold');
    @endphp

    @php
        $card = 'border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:20px;';
        $btn = 'padding:8px 16px;background:#0F766E;color:#fff;border:none;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block;';
        $btnGhost = 'padding:8px 16px;background:#fff;color:#0F766E;border:1px solid #0F766E;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block;';
        $inp = 'padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin:2px;';
        $lbl = 'font-weight:600;font-size:15px;margin-bottom:8px;display:block;';
    @endphp

    <div style="max-width:820px;margin:0 auto;padding:24px;">
        <h1 style="font-size:20px;font-weight:700;margin-bottom:16px;">Set up your opening balances</h1>

        @if (session('success'))
            <div style="padding:10px 14px;background:#ecfdf5;color:#065f46;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div style="padding:10px 14px;background:#fef2f2;color:#991b1b;border-radius:8px;margin-bottom:16px;">{{ $errors->first() }}</div>
        @endif

        {{-- ═══ LANDING: decision between Start Clean and Migrate ═══ --}}
        @if ($state === 'landing')
            <div style="padding:14px 16px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;margin-bottom:16px;">
                <p style="font-weight:700;color:#0f766e;margin-bottom:4px;">Before using JewelFlow, choose how your shop should start.</p>
                <p style="color:#475569;font-size:13px;">This is a one-time setup step. It decides whether your reports begin from zero or from your existing shop balances.</p>
            </div>
            @if ($cancelledNotice)
                <div style="padding:10px 14px;background:#fffbeb;color:#92400e;border-radius:8px;margin-bottom:16px;">
                    Previous opening setup was cancelled. You can start again if needed.
                </div>
            @endif

            <p style="color:#475569;font-size:14px;margin-bottom:8px;">
                Already running your shop before JewelFlow? Enter what you have on hand today — cash, stock,
                vault metal, customer dues, supplier and karigar balances — so your reports start from the right numbers.
            </p>
            <p style="color:#475569;font-size:14px;margin-bottom:20px;">
                Starting fresh? You can skip this and begin using JewelFlow normally.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Start Clean</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:16px;">My shop starts fresh in JewelFlow. No past balances to enter.</p>
                    <form method="POST" action="{{ route('onboarding.start-clean') }}" onsubmit="return confirm('Start clean with no opening balances?');">
                        @csrf
                        <button type="submit" style="{{ $btnGhost }}">Start clean</button>
                    </form>
                </div>
                <div style="{{ $card }}background:#f0fdfa;">
                    <label style="{{ $lbl }}">Migrate Existing Shop</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:16px;">My shop was already running. I want to enter opening balances.</p>
                    <a href="{{ route('onboarding.index', ['step' => 'migrate']) }}" style="{{ $btn }}">Begin migration</a>
                </div>
            </div>

        {{-- ═══ STARTED CLEAN ═══ --}}
        @elseif ($state === 'clean')
            <div style="{{ $card }}background:#f8fafc;">
                <label style="{{ $lbl }}">Started clean</label>
                <p style="color:#475569;font-size:14px;margin-bottom:12px;">
                    This shop is set to start fresh with no opening balances. Use JewelFlow normally —
                    add stock, customers, and sales as they happen.
                </p>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">
                    Changed your mind and want to enter pre-JewelFlow balances instead?
                </p>
                <a href="{{ route('onboarding.index', ['step' => 'migrate']) }}" style="{{ $btnGhost }}">Begin migration</a>
            </div>

        {{-- ═══ MIGRATE: go-live date + preparation checklist ═══ --}}
        @elseif ($state === 'migrate')
            @if ($hasLiveSales)
                <div style="padding:12px 14px;background:#fef2f2;color:#991b1b;border-radius:8px;margin-bottom:16px;font-size:13px;">
                    <strong>This shop already has live transactions in JewelFlow.</strong>
                    Opening balances are meant for pre-JewelFlow data. Continue only if you are entering
                    balances from before your JewelFlow go-live date.
                </div>
            @endif

            <div style="{{ $card }}">
                <label style="{{ $lbl }}">Have these ready before you start</label>
                <ul style="color:#475569;font-size:13px;line-height:1.8;margin:0;padding-left:18px;">
                    <li>Cash / bank / UPI / card balances as of go-live</li>
                    <li>Customer list</li>
                    <li>Customer dues, advances, gold balances</li>
                    <li>Finished stock</li>
                    <li>Vault / loose bullion</li>
                    <li>Supplier payables</li>
                    <li>Karigar money and held gold</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('onboarding.store') }}" style="{{ $card }}">
                @csrf
                <label style="{{ $lbl }}">Pick your JewelFlow go-live date</label>
                <input type="date" name="start_date" required style="{{ $inp }}">
                <p style="color:#64748b;font-size:13px;margin-top:8px;">
                    Opening balances are recorded as of the day before this date, so they never show up as
                    sales, GST, or profit. Choose the date you start billing live in JewelFlow.
                </p>
                <div style="display:flex;gap:12px;margin-top:12px;">
                    <button type="submit" style="{{ $btn }}">Start migration</button>
                    <a href="{{ route('onboarding.index') }}" style="{{ $btnGhost }}">Back</a>
                </div>
            </form>

            <p style="color:#92400e;font-size:13px;">
                This does not create fake sales or purchases. Opening balances stay separate from live trading.
                After you lock, entries are final — later fixes are made as adjustment entries.
            </p>

        {{-- ═══ LOCKED: post-lock summary ═══ --}}
        @elseif ($state === 'locked')
            <div style="{{ $card }}background:#ecfdf5;">
                <label style="{{ $lbl }}color:#065f46;">Opening balances locked</label>
                <p style="font-size:14px;color:#065f46;margin-bottom:6px;">
                    <strong>Posted as of:</strong> {{ $lockedLatest->as_of_date->toDateString() }}
                    &nbsp;|&nbsp; <strong>Live use starts from:</strong> {{ $lockedLatest->start_date->toDateString() }}
                </p>
                <p style="color:#475569;font-size:13px;margin-bottom:12px;">
                    These opening balances now appear as your starting position in Cash Book, Vault,
                    customer balances, supplier balances, and karigar balances. Live sales, GST, and profit
                    begin from the go-live date.
                </p>

                @php $snap = $lockedLatest->totals_snapshot; @endphp
                @if (is_array($snap) && count($snap))
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px;">
                        @foreach ($snap as $k => $v)
                            @if (is_scalar($v))
                                <tr><td style="padding:3px;color:#475569;">{{ ucwords(str_replace('_', ' ', (string) $k)) }}</td><td style="text-align:right;">{{ $v }}</td></tr>
                            @endif
                        @endforeach
                    </table>
                @endif
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                <a href="{{ route('onboarding.suppliers') }}" style="{{ $btnGhost }}">Supplier opening summary</a>
                <a href="{{ route('dashboard') }}" style="{{ $btnGhost }}">Go to dashboard</a>
                <a href="{{ route('cashbook.index') }}" style="{{ $btnGhost }}">Go to cash book</a>
                <a href="{{ route('vault.index') }}" style="{{ $btnGhost }}">Go to vault</a>
            </div>

        {{-- ═══ RESUME: migration in progress — the staging wizard ═══ --}}
        @elseif ($state === 'resume')
            <div style="{{ $card }}background:#f8fafc;">
                <p style="font-weight:600;margin-bottom:4px;">Opening balance setup in progress — resume your migration</p>
                <p style="color:#0f766e;font-size:13px;margin-bottom:8px;">Live billing will unlock after you lock opening balances.</p>
                <p><strong>Status:</strong> {{ ucfirst($batch->status) }}
                   &nbsp;|&nbsp; <strong>Go-live:</strong> {{ $batch->start_date->toDateString() }}
                   &nbsp;|&nbsp; <strong>Opening as-of:</strong> {{ $batch->as_of_date->toDateString() }}</p>
            </div>

            @if ($batch->isEditable())
                {{-- Step 2: Customers (CSV import) --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 2 — Customers (CSV import)</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:8px;">Header row: <code>first_name,last_name,mobile,email,address</code>. Deduped by mobile.</p>
                    <form method="POST" action="{{ route('onboarding.customers.import', $batch) }}" enctype="multipart/form-data">
                        @csrf
                        <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                        <button type="submit" style="{{ $btn }}">Import customers</button>
                    </form>
                </div>

                {{-- Step 3: Cash / bank / UPI / card / wallet --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 3 — Cash & bank balances</label>
                    <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                        @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_CASH }}">
                        <select name="payment_mode" style="{{ $inp }}" required>
                            @foreach (['cash','bank','upi','card','wallet'] as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                        </select>
                        <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount ₹" required style="{{ $inp }}">
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>
                    @include('onboarding._staged', ['rows' => $entries[$OE::KIND_CASH] ?? collect(), 'batch' => $batch, 'cols' => ['payment_mode' => 'Mode', 'amount' => '₹']])
                </div>

                {{-- Step 4: Opening stock --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 4 — Opening finished stock</label>
                    <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                        @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_STOCK_ITEM }}">
                        <select name="metal_type" style="{{ $inp }}" required>
                            @foreach ($metals as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                        </select>
                        <input type="number" step="0.001" min="0.001" name="gross_weight" placeholder="Gross g" required style="{{ $inp }}width:90px;">
                        <input type="number" step="0.001" min="0" name="stone_weight" placeholder="Stone g" style="{{ $inp }}width:90px;">
                        <input type="number" step="0.01" min="0" name="purity" placeholder="Purity" required style="{{ $inp }}width:80px;">
                        <input type="number" step="0.01" min="0" name="cost_price" placeholder="Cost ₹" style="{{ $inp }}width:90px;">
                        <input type="text" name="barcode" placeholder="Barcode" style="{{ $inp }}width:110px;">
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>
                    @include('onboarding._staged', ['rows' => $entries[$OE::KIND_STOCK_ITEM] ?? collect(), 'batch' => $batch, 'cols' => ['metal_type' => 'Metal', 'gross_weight' => 'Gross', 'purity' => 'Purity', 'cost_price' => 'Cost ₹']])
                </div>

                {{-- Step 5: Vault metal --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 5 — Vault / loose bullion</label>
                    <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                        @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_VAULT_METAL }}">
                        <select name="metal_type" style="{{ $inp }}" required>
                            @foreach ($metals as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                        </select>
                        <input type="number" step="0.01" min="0" name="purity" placeholder="Purity" required style="{{ $inp }}width:80px;">
                        <input type="number" step="0.001" min="0.001" name="fine_weight" placeholder="Fine g" required style="{{ $inp }}width:90px;">
                        <input type="number" step="0.01" min="0" name="cost_per_fine_gram" placeholder="Cost/fine g ₹" style="{{ $inp }}width:120px;">
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>
                    @include('onboarding._staged', ['rows' => $entries[$OE::KIND_VAULT_METAL] ?? collect(), 'batch' => $batch, 'cols' => ['metal_type' => 'Metal', 'purity' => 'Purity', 'fine_weight' => 'Fine g']])
                </div>

                {{-- Step 6: Customer opening balances --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 6 — Customer opening balances</label>
                    @if ($customers->isEmpty())
                        <p style="color:#991b1b;font-size:13px;">Add customers first (Step 2) to record their balances.</p>
                    @else
                        @foreach ([$OE::KIND_CUSTOMER_RECEIVABLE => 'Owes shop (receivable)', $OE::KIND_CUSTOMER_PAYABLE => 'Shop owes (payable)', $OE::KIND_CUSTOMER_ADVANCE => 'Advance / store credit'] as $kind => $title)
                            <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}" style="margin-bottom:6px;">
                                @csrf <input type="hidden" name="kind" value="{{ $kind }}">
                                <span style="display:inline-block;width:160px;font-size:13px;color:#475569;">{{ $title }}</span>
                                <select name="customer_id" style="{{ $inp }}" required>
                                    @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $customerLabel($c) }}</option>@endforeach
                                </select>
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="₹" required style="{{ $inp }}width:100px;">
                                <button type="submit" style="{{ $btn }}">Add</button>
                            </form>
                        @endforeach
                        <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                            @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_CUSTOMER_GOLD }}">
                            <span style="display:inline-block;width:160px;font-size:13px;color:#475569;">Gold balance (fine g)</span>
                            <select name="customer_id" style="{{ $inp }}" required>
                                @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $customerLabel($c) }}</option>@endforeach
                            </select>
                            <input type="number" step="0.001" name="fine_gold" placeholder="Fine g" required style="{{ $inp }}width:100px;">
                            <button type="submit" style="{{ $btn }}">Add</button>
                        </form>
                        @foreach ([$OE::KIND_CUSTOMER_RECEIVABLE, $OE::KIND_CUSTOMER_PAYABLE, $OE::KIND_CUSTOMER_ADVANCE, $OE::KIND_CUSTOMER_GOLD] as $k)
                            @include('onboarding._staged', ['rows' => $entries[$k] ?? collect(), 'batch' => $batch, 'cols' => ['customer_id' => 'Cust#', 'amount' => '₹', 'fine_gold' => 'Fine g']])
                        @endforeach
                    @endif
                </div>

                {{-- Step 7: Suppliers & karigars --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Step 7 — Suppliers & karigars</label>
                    @if ($vendors->isNotEmpty())
                        @foreach ([$OE::KIND_SUPPLIER_PAYABLE => 'Shop owes supplier', $OE::KIND_SUPPLIER_RECEIVABLE => 'Supplier owes shop'] as $kind => $title)
                            <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}" style="margin-bottom:6px;">
                                @csrf <input type="hidden" name="kind" value="{{ $kind }}">
                                <span style="display:inline-block;width:160px;font-size:13px;color:#475569;">{{ $title }}</span>
                                <select name="vendor_id" style="{{ $inp }}" required>
                                    @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                </select>
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="₹" required style="{{ $inp }}width:100px;">
                                <button type="submit" style="{{ $btn }}">Add</button>
                            </form>
                        @endforeach
                    @else
                        <p style="color:#64748b;font-size:13px;">No suppliers in directory — add vendors to record supplier balances.</p>
                    @endif
                    @if ($karigars->isNotEmpty())
                        <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}" style="margin:6px 0;">
                            @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_KARIGAR_MONEY }}">
                            <span style="display:inline-block;width:160px;font-size:13px;color:#475569;">Karigar money</span>
                            <select name="karigar_id" style="{{ $inp }}" required>
                                @foreach ($karigars as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                            </select>
                            <input type="number" step="0.01" name="amount" placeholder="₹ (+owed to karigar)" required style="{{ $inp }}width:150px;">
                            <button type="submit" style="{{ $btn }}">Add</button>
                        </form>
                        <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                            @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_KARIGAR_GOLD }}">
                            <span style="display:inline-block;width:160px;font-size:13px;color:#475569;">Karigar-held gold</span>
                            <select name="karigar_id" style="{{ $inp }}" required>
                                @foreach ($karigars as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                            </select>
                            <select name="metal_type" style="{{ $inp }}" required>
                                @foreach ($metals as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                            </select>
                            <input type="number" step="0.01" name="purity" placeholder="Purity" required style="{{ $inp }}width:80px;">
                            <input type="number" step="0.001" name="fine_weight" placeholder="Fine g" required style="{{ $inp }}width:90px;">
                            <button type="submit" style="{{ $btn }}">Add</button>
                        </form>
                    @endif
                    @foreach ([$OE::KIND_SUPPLIER_PAYABLE, $OE::KIND_SUPPLIER_RECEIVABLE, $OE::KIND_KARIGAR_MONEY, $OE::KIND_KARIGAR_GOLD] as $k)
                        @include('onboarding._staged', ['rows' => $entries[$k] ?? collect(), 'batch' => $batch, 'cols' => ['vendor_id' => 'Vend#', 'karigar_id' => 'Kari#', 'amount' => '₹', 'fine_weight' => 'Fine g']])
                    @endforeach
                </div>

                {{-- Step 8: Review / reconcile --}}
                <div style="{{ $card }}background:#fffbeb;">
                    <label style="{{ $lbl }}">Step 8 — Review & reconcile</label>
                    <table style="width:100%;border-collapse:collapse;font-size:14px;">
                        <tr><td style="padding:4px;">Vault fine (g)</td><td style="text-align:right;">{{ number_format($vaultFine, 3) }}</td></tr>
                        <tr><td style="padding:4px;">Karigar-held fine (g)</td><td style="text-align:right;">{{ number_format($karigarHeldFine, 3) }}</td></tr>
                        <tr><td style="padding:4px;">Customer gold fine (g)</td><td style="text-align:right;">{{ number_format($customerGoldFine, 3) }}</td></tr>
                        <tr><td style="padding:4px;">Opening stock fine (g)</td><td style="text-align:right;">{{ number_format($stockFine, 3) }}</td></tr>
                        <tr style="border-top:1px solid #d1d5db;font-weight:700;"><td style="padding:4px;">Total fine (g)</td><td style="text-align:right;">{{ number_format($vaultFine + $karigarHeldFine + $customerGoldFine + $stockFine, 3) }}</td></tr>
                        <tr><td colspan="2" style="padding-top:8px;"></td></tr>
                        @foreach ($cashByMode as $mode => $amt)
                            <tr><td style="padding:4px;">Cash — {{ ucfirst($mode) }}</td><td style="text-align:right;">₹{{ number_format($amt, 2) }}</td></tr>
                        @endforeach
                        <tr><td style="padding:4px;">Customer receivable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_RECEIVABLE, 'amount'), 2) }}</td></tr>
                        <tr><td style="padding:4px;">Customer payable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_PAYABLE, 'amount'), 2) }}</td></tr>
                        <tr><td style="padding:4px;">Customer advances</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_ADVANCE, 'amount'), 2) }}</td></tr>
                        <tr><td style="padding:4px;">Supplier payable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_SUPPLIER_PAYABLE, 'amount'), 2) }}</td></tr>
                    </table>
                    <p style="color:#92400e;font-size:13px;margin-top:8px;">Reconcile these against your physical count before locking. Locking is final — post-lock changes are adjustment entries in the live ledgers.</p>
                </div>

                {{-- Step 9: Lock --}}
                <div style="display:flex;gap:12px;margin-bottom:24px;">
                    <form method="POST" action="{{ route('onboarding.lock', $batch) }}" onsubmit="return confirm('Lock is final. Post all opening balances?');">
                        @csrf <button type="submit" style="{{ $btn }}">Lock &amp; post opening balances</button>
                    </form>
                    <form method="POST" action="{{ route('onboarding.cancel', $batch) }}" onsubmit="return confirm('Discard this onboarding batch?');">
                        @csrf <button type="submit" style="padding:8px 16px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;cursor:pointer;">Cancel batch</button>
                    </form>
                </div>
            @else
                <div style="{{ $card }}">
                    <p style="color:#065f46;">This batch is {{ $batch->status }} — opening balances are being posted.</p>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
