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
        $card = 'border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px;background:#ffffff;box-shadow:none;';
        $btn = 'padding:11px 17px;background:#b45309;color:#fff;border:1px solid #b45309;border-radius:9px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:13px;font-weight:700;line-height:1.2;min-height:42px;';
        $btnGhost = 'padding:11px 17px;background:#fff;color:#92400e;border:1px solid #f3dcb6;border-radius:9px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:13px;font-weight:700;line-height:1.2;min-height:42px;';
        $inp = 'padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;margin:3px;background:#f8fafc;color:#0f172a;font-size:13px;min-height:40px;';
        $lbl = 'font-weight:700;font-size:15px;margin-bottom:8px;display:block;color:#0f172a;';
    @endphp

    <style>
        .onboarding-page {
            max-width: 1180px !important;
            margin: 0 auto !important;
            padding: 22px !important;
            color: #334155;
        }
        .onboarding-hero-band {
            width: 100%;
            margin: 0 0 18px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf5 100%);
        }
        .onboarding-hero {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            max-width: 1180px;
            margin: 0 auto;
            padding: 14px 22px;
        }
        .onboarding-kicker {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            margin-bottom: 6px;
            padding: 3px 8px;
            border: 1px solid #fed7aa;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .onboarding-page h1,
        .onboarding-hero h1 {
            margin: 0 !important;
            color: #0f172a !important;
            font-size: clamp(20px, 2.2vw, 23px) !important;
            line-height: 1.15 !important;
            font-weight: 800 !important;
            letter-spacing: 0 !important;
        }
        .onboarding-hero p {
            max-width: 620px;
            margin: 5px 0 0;
            color: #64748b;
            font-size: 12.5px;
            line-height: 1.4;
        }
        .onboarding-page a {
            color: #b45309;
        }
        .onboarding-page a:not([style*="padding"]) {
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid rgba(180, 83, 9, .28);
        }
        .onboarding-page a[href*="template"] {
            display: inline-flex;
            align-items: center;
            margin-left: 6px;
            padding: 5px 9px;
            border: 1px solid #fed7aa !important;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412 !important;
            font-size: 12px !important;
            font-weight: 800;
            line-height: 1.2;
            text-decoration: none;
        }
        .onboarding-page form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        .onboarding-page input,
        .onboarding-page select,
        .onboarding-page textarea {
            max-width: 100%;
            box-sizing: border-box;
        }
        .onboarding-page input:focus,
        .onboarding-page select:focus,
        .onboarding-page textarea:focus {
            border-color: #b45309 !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, .16) !important;
            outline: none !important;
        }
        .onboarding-page button[style*="background:#b45309"]:hover,
        .onboarding-page a[style*="background:#b45309"]:hover {
            background: #92400e !important;
            border-color: #92400e !important;
        }
        .onboarding-page button[style*="color:#92400e"]:hover,
        .onboarding-page a[style*="color:#92400e"]:hover {
            background: #fff7ed !important;
            border-color: #d97706 !important;
            color: #78350f !important;
        }
        .onboarding-page button,
        .onboarding-page a[style*="padding:11px"] {
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }
        .onboarding-page button[style*="color:#991b1b"] {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 6px 11px !important;
            border-radius: 999px !important;
            background: #fff1f2 !important;
            border-color: #fecdd3 !important;
            color: #be123c !important;
            font-size: 12px !important;
            font-weight: 800 !important;
        }
        .onboarding-page button[style*="color:#991b1b"]:hover {
            background: #ffe4e6 !important;
            border-color: #fda4af !important;
        }
        .onboarding-page table {
            min-width: 640px;
            color: #334155;
            border-radius: 12px;
        }
        .onboarding-page th {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .onboarding-page td {
            font-size: 13px;
            font-weight: 400;
        }
        .onboarding-page tr {
            background: #fff;
        }
        .onboarding-page tbody tr:hover {
            background: #fffaf5;
        }
        .onboarding-page details {
            display: inline-block;
        }
        .onboarding-page form[style*="border:1px solid #e2e8f0"] {
            display: grid !important;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 12px !important;
            align-items: end;
        }
        .onboarding-page form[style*="border:1px solid #e2e8f0"] > label,
        .onboarding-page form[style*="border:1px solid #e2e8f0"] > p,
        .onboarding-page form[style*="border:1px solid #e2e8f0"] > div {
            grid-column: 1 / -1;
        }
        .onboarding-page form[style*="border:1px solid #e2e8f0"] > input,
        .onboarding-page form[style*="border:1px solid #e2e8f0"] > select {
            grid-column: span 4;
        }
        .onboarding-page details[open] {
            display: block;
            width: min(100%, 980px);
            margin: 6px 0;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }
        .onboarding-page summary {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 6px 11px;
            border: 1px solid #fed7aa;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412 !important;
            font-size: 12px !important;
            font-weight: 700;
            line-height: 1.15;
        }
        .onboarding-choice-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        .onboarding-stepper {
            display: flex !important;
            gap: 8px !important;
            overflow-x: auto !important;
            flex-wrap: nowrap !important;
            margin-bottom: 20px !important;
            padding: 8px !important;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            scrollbar-width: thin;
        }
        .onboarding-stepper a {
            white-space: nowrap;
            flex: 0 0 auto;
            padding: 9px 12px !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 999px !important;
            background: #f8fafc !important;
            color: #475569 !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            text-decoration: none !important;
        }
        .onboarding-stepper a[style*="background:#b45309"] {
            border-color: #b45309 !important;
            background: #b45309 !important;
            color: #fff !important;
        }
        .onboarding-step-shell {
            padding: 16px !important;
            background: #f8fafc !important;
        }
        .onboarding-step-shell > label:first-child {
            margin: 2px 4px 4px !important;
            font-size: 17px !important;
        }
        .onboarding-step-shell > label:first-child + p {
            margin: 0 4px 16px !important;
            max-width: 760px;
        }
        .onboarding-step-shell > div[style*="border:1px dashed"],
        .onboarding-step-shell > div[style*="margin-bottom:16px"],
        .onboarding-step-shell > div[style*="margin-bottom:12px"],
        .onboarding-step-shell > div[style*="margin-bottom:10px"],
        .onboarding-step-shell > form:not([style*="display:inline"]),
        .onboarding-step-shell > table,
        .onboarding-step-shell > div[style*="max-height:320px"] {
            margin-top: 14px !important;
            margin-bottom: 0 !important;
            padding: 16px !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 14px !important;
            background: #ffffff !important;
            box-shadow: none !important;
        }
        .onboarding-step-shell > div[style*="border:1px dashed"] {
            border-color: #f3dcb6 !important;
            background: #fffaf5 !important;
        }
        .onboarding-step-shell > table {
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .onboarding-step-shell > p[style*="font-weight:600"] {
            margin: 18px 4px 0 !important;
        }
        .onboarding-table-wrap {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px !important;
        }
        .onboarding-page div[style*="border:1px dashed"] {
            border: 1px solid #f3dcb6 !important;
            border-radius: 14px !important;
            padding: 14px !important;
            background: #fffaf5 !important;
        }
        .onboarding-page div[style*="border:1px dashed"] > p:first-child {
            color: #0f172a !important;
            font-size: 13px !important;
            font-weight: 800 !important;
        }
        .onboarding-page code {
            padding: 2px 6px;
            border-radius: 6px;
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
        }
        @media (max-width: 760px) {
            .onboarding-page {
                padding: 14px 10px 88px !important;
            }
            .onboarding-hero {
                display: block;
                padding: 12px 10px;
            }
            .onboarding-choice-grid {
                grid-template-columns: 1fr !important;
            }
            .onboarding-page form {
                align-items: stretch;
            }
            .onboarding-page input,
            .onboarding-page select,
            .onboarding-page button,
            .onboarding-page a[style*="padding"] {
                width: 100% !important;
                min-width: 0 !important;
            }
            .onboarding-page input[type="file"] {
                font-size: 12px !important;
            }
            .onboarding-page form[style*="border:1px solid #e2e8f0"] {
                display: flex !important;
            }
            .onboarding-page table {
                min-width: 560px;
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .onboarding-page h1 {
                font-size: 22px !important;
            }
            .onboarding-stepper a {
                width: 100% !important;
                min-height: 38px;
                justify-content: center;
                text-align: center;
                white-space: normal;
            }
            .onboarding-page a[href*="template"] {
                width: fit-content !important;
                margin: 8px 0 0 !important;
            }
            .onboarding-stepper {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                overflow: visible !important;
            }
        }
        @media (min-width: 761px) {
            .onboarding-page input[style*="width:"],
            .onboarding-page select[style*="width:"] {
                min-width: 150px;
            }
            .onboarding-page input[type="date"] {
                min-width: 170px;
            }
        }
        @media (max-width: 360px) {
            .onboarding-page {
                padding-inline: 8px !important;
            }
            .onboarding-page table {
                min-width: 520px;
            }
        }
    </style>

    <div class="onboarding-hero-band">
        <div class="onboarding-hero">
            <div>
                <span class="onboarding-kicker">Opening setup</span>
                <h1 style="font-size:24px;font-weight:800;margin-bottom:0;">Set up your opening balances</h1>
                <p>Enter old shop balances once. Live billing stays locked until this shield is posted.</p>
            </div>
        </div>
    </div>

    <div class="onboarding-page" style="max-width:1180px;margin:0 auto;padding:22px;">
        @if (session('success'))
            <div style="padding:10px 14px;background:#ecfdf5;color:#065f46;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div style="padding:10px 14px;background:#fef2f2;color:#991b1b;border-radius:8px;margin-bottom:16px;">{{ $errors->first() }}</div>
        @endif

        {{-- ═══ LANDING: decision between Start Clean and Migrate ═══ --}}
        @if ($state === 'landing')
            <div style="padding:14px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;margin-bottom:16px;">
                <p style="font-weight:700;color:#b45309;margin-bottom:4px;">Before using JewelFlows, choose how your shop should start.</p>
                <p style="color:#475569;font-size:13px;">This is a one-time setup step. It decides whether your reports begin from zero or from your existing shop balances.</p>
            </div>
            @if ($cancelledNotice)
                <div style="padding:10px 14px;background:#fffbeb;color:#92400e;border-radius:8px;margin-bottom:16px;">
                    Previous opening setup was cancelled. You can start again if needed.
                </div>
            @endif

            <p style="color:#475569;font-size:14px;margin-bottom:8px;">
                Already running your shop before JewelFlows? Enter what you have on hand today — cash, stock,
                vault metal, customer dues, supplier and karigar balances — so your reports start from the right numbers.
            </p>
            <p style="color:#475569;font-size:14px;margin-bottom:20px;">
                Starting fresh? You can skip this and begin using JewelFlows normally.
            </p>

            <div class="onboarding-choice-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">Start Clean</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:16px;">My shop starts fresh in JewelFlows. No past balances to enter.</p>
                    <form method="POST" action="{{ route('onboarding.start-clean') }}" onsubmit="return confirm('Start clean with no opening balances?');">
                        @csrf
                        <button type="submit" style="{{ $btnGhost }}">Start clean</button>
                    </form>
                </div>
                <div style="{{ $card }}background:#fff7ed;">
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
                    This shop is set to start fresh with no opening balances. Use JewelFlows normally —
                    add stock, customers, and sales as they happen.
                </p>
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">
                    Changed your mind and want to enter pre-JewelFlows balances instead?
                </p>
                <a href="{{ route('onboarding.index', ['step' => 'migrate']) }}" style="{{ $btnGhost }}">Begin migration</a>
            </div>

        {{-- ═══ MIGRATE: go-live date + preparation checklist ═══ --}}
        @elseif ($state === 'migrate')
            @if ($hasLiveSales)
                <div style="padding:12px 14px;background:#fef2f2;color:#991b1b;border-radius:8px;margin-bottom:16px;font-size:13px;">
                    <strong>This shop already has live transactions in JewelFlows.</strong>
                    Opening balances are meant for pre-JewelFlows data. Continue only if you are entering
                    balances from before your JewelFlows go-live date.
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
                <label style="{{ $lbl }}">Pick your JewelFlows go-live date</label>
                <input type="date" name="start_date" required style="{{ $inp }}">
                <p style="color:#64748b;font-size:13px;margin-top:8px;">
                    Opening balances are recorded as of the day before this date, so they never show up as
                    sales, GST, or profit. Choose the date you start billing live in JewelFlows.
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
                <p style="color:#b45309;font-size:13px;margin-bottom:8px;">Live billing will unlock after you lock opening balances.</p>
                <p><strong>Status:</strong> {{ ucfirst($batch->status) }}
                   &nbsp;|&nbsp; <strong>Go-live:</strong> {{ $batch->start_date->toDateString() }}
                   &nbsp;|&nbsp; <strong>Opening as-of:</strong> {{ $batch->as_of_date->toDateString() }}</p>
            </div>

            @if ($batch->isEditable())
                @php
                    // One step per page. Labels double as the stepper header; the
                    // active step ($step) is URL-driven so Back/Continue are just links.
                    $labels = [
                        'customers' => 'Customers',
                        'cash'      => 'Cash & bank',
                        'stock'     => 'Finished stock',
                        'vault'     => 'Vault metal',
                        'balances'  => 'Customer balances',
                        'suppliers' => 'Suppliers & karigars',
                        'review'    => 'Review & lock',
                    ];
                    $stepKeys = array_keys($labels);
                    $i    = array_search($step, $stepKeys, true);
                    $prev = $i > 0 ? $stepKeys[$i - 1] : null;
                    $next = $i < count($stepKeys) - 1 ? $stepKeys[$i + 1] : null;
                @endphp

                {{-- Stepper header --}}
                <div class="onboarding-stepper" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
                    @foreach ($stepKeys as $n => $key)
                        @php $active = $key === $step; @endphp
                        <a href="{{ route('onboarding.index', ['step' => $key]) }}"
                           style="padding:6px 12px;border-radius:20px;font-size:13px;text-decoration:none;
                                  {{ $active ? 'background:#b45309;color:#fff;font-weight:600;' : 'background:#f1f5f9;color:#475569;' }}">
                            {{ $n + 1 }}. {{ $labels[$key] }}
                        </a>
                    @endforeach
                </div>

                {{-- ── Step: Customers ── --}}
                @if ($step === 'customers')
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Customers</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:12px;">
                        Import your customer list from a CSV, or add customers one at a time below. Both feed the
                        same list. Deduped by mobile — duplicates are skipped.
                    </p>

                    {{-- CSV import --}}
                    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                        <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                            Header row: <code>first_name,last_name,mobile,email,address</code>.
                            <a href="{{ route('onboarding.customers.template') }}" style="color:#b45309;">Download sample CSV</a>
                        </p>
                        <form method="POST" action="{{ route('onboarding.customers.import', $batch) }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                            <button type="submit" style="{{ $btn }}">Import customers</button>
                        </form>
                    </div>

                    {{-- Manual add --}}
                    <div style="margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Add one customer</p>
                        <form method="POST" action="{{ route('onboarding.customers.store', $batch) }}">
                            @csrf
                            <input type="text" name="first_name" placeholder="First name *" required value="{{ old('first_name') }}" style="{{ $inp }}width:130px;">
                            <input type="text" name="last_name" placeholder="Last name" value="{{ old('last_name') }}" style="{{ $inp }}width:130px;">
                            <input type="text" name="mobile" placeholder="Mobile *" required value="{{ old('mobile') }}" style="{{ $inp }}width:120px;">
                            <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" style="{{ $inp }}width:170px;">
                            <input type="text" name="address" placeholder="Address" value="{{ old('address') }}" style="{{ $inp }}width:200px;">
                            <button type="submit" style="{{ $btn }}">Add customer</button>
                        </form>
                    </div>

                    {{-- Preview table --}}
                    <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Customers so far ({{ $customers->count() }})</p>
                    @if ($customers->isEmpty())
                        <p style="color:#64748b;font-size:13px;">None yet — import a CSV or add one above.</p>
                    @else
                        <div style="max-height:320px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">
                            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                                <thead>
                                    <tr style="background:#f8fafc;text-align:left;">
                                        <th style="padding:8px;width:36px;">#</th>
                                        <th style="padding:8px;">Name</th>
                                        <th style="padding:8px;">Mobile</th>
                                        <th style="padding:8px;">Email</th>
                                        <th style="padding:8px;">Address</th>
                                        <th style="padding:8px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($customers as $c)
                                        <tr style="border-top:1px solid #f1f5f9;">
                                            <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                            <td style="padding:8px;">{{ trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) }}</td>
                                            <td style="padding:8px;">{{ $c->mobile }}</td>
                                            <td style="padding:8px;color:#64748b;">{{ $c->email ?: '—' }}</td>
                                            <td style="padding:8px;color:#64748b;">{{ $c->address ?: '—' }}</td>
                                            <td style="padding:8px;white-space:nowrap;">
                                                <details>
                                                    <summary style="cursor:pointer;color:#b45309;display:inline;">Edit</summary>
                                                    <form method="POST" action="{{ route('onboarding.customers.update', [$batch, $c]) }}" style="margin-top:6px;">
                                                        @csrf @method('PUT')
                                                        <input type="text" name="first_name" value="{{ $c->first_name }}" required style="{{ $inp }}width:110px;">
                                                        <input type="text" name="last_name" value="{{ $c->last_name }}" style="{{ $inp }}width:110px;">
                                                        <input type="text" name="mobile" value="{{ $c->mobile }}" required style="{{ $inp }}width:110px;">
                                                        <input type="email" name="email" value="{{ $c->email }}" style="{{ $inp }}width:150px;">
                                                        <input type="text" name="address" value="{{ $c->address }}" style="{{ $inp }}width:170px;">
                                                        <button type="submit" style="{{ $btn }}">Save</button>
                                                    </form>
                                                </details>
                                                <form method="POST" action="{{ route('onboarding.customers.destroy', [$batch, $c]) }}" onsubmit="return confirm('Remove this customer?');" style="display:inline;">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                @endif

                {{-- ── Step: Cash / bank / UPI / wallet ── --}}
                @if ($step === 'cash')
                @php
                    $pmTypes  = ['bank' => 'Bank', 'upi' => 'UPI', 'wallet' => 'Wallet'];
                    $cashRows = $entries[$OE::KIND_CASH] ?? collect();
                    // Reusable source <select>: Cash in drawer + every configured account.
                    $sourceOptions = function ($selected = 'cash') use ($pmTypes, $paymentMethods, $inp) {
                        $html = '<select name="source" required style="' . $inp . '">';
                        $html .= '<option value="cash"' . ($selected === 'cash' ? ' selected' : '') . '>Cash in drawer</option>';
                        foreach ($pmTypes as $t => $label) {
                            $ms = $paymentMethods->get($t, collect());
                            if ($ms->isEmpty()) continue;
                            $html .= '<optgroup label="' . $label . '">';
                            foreach ($ms as $m) {
                                $sel = (string) $selected === (string) $m->id ? ' selected' : '';
                                $html .= '<option value="' . $m->id . '"' . $sel . '>' . e($m->account_label) . '</option>';
                            }
                            $html .= '</optgroup>';
                        }
                        return $html . '</select>';
                    };
                @endphp

                {{-- Your accounts — created here also appear in Settings → Payment Methods --}}
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Your bank / UPI / wallet accounts</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:12px;">
                        Add the accounts your shop holds money in. These save into Settings → Payment Methods and
                        become selectable below and across the app.
                    </p>
                    @foreach ($pmTypes as $t => $label)
                        @php $ms = $paymentMethods->get($t, collect()); @endphp
                        <div style="margin-bottom:10px;">
                            <p style="font-weight:600;font-size:14px;margin-bottom:4px;">{{ $label }}
                                <span style="color:#94a3b8;font-weight:400;">({{ $ms->count() }})</span></p>
                            @if ($ms->isNotEmpty())
                                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px;">
                                    <thead>
                                        <tr style="background:#f8fafc;text-align:left;">
                                            <th style="padding:6px 8px;width:36px;">#</th>
                                            <th style="padding:6px 8px;">Name</th>
                                            <th style="padding:6px 8px;">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($ms as $m)
                                            @php
                                                $details = match ($t) {
                                                    'upi'    => $m->upi_id,
                                                    'wallet' => $m->wallet_id,
                                                    'bank'   => trim(implode(' · ', array_filter([
                                                        $m->bank_name,
                                                        $m->account_number ? '****' . substr($m->account_number, -4) : null,
                                                        $m->ifsc_code,
                                                        $m->branch,
                                                    ]))),
                                                    default  => null,
                                                };
                                            @endphp
                                            <tr style="border-top:1px solid #f1f5f9;">
                                                <td style="padding:6px 8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                                <td style="padding:6px 8px;">{{ $m->name }}</td>
                                                <td style="padding:6px 8px;color:#64748b;">{{ $details ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            <details>
                                <summary style="cursor:pointer;color:#b45309;font-size:13px;">+ Add {{ $label }}</summary>
                                <form method="POST" action="{{ route('settings.payment-methods.store') }}" style="margin-top:6px;">
                                    @csrf <input type="hidden" name="type" value="{{ $t }}">
                                    <input type="text" name="name" placeholder="Name *" required style="{{ $inp }}width:150px;">
                                    @if ($t === 'upi')
                                        <input type="text" name="upi_id" placeholder="UPI ID (name@upi)" style="{{ $inp }}width:180px;">
                                    @elseif ($t === 'bank')
                                        <input type="text" name="account_holder" placeholder="Account holder" style="{{ $inp }}width:150px;">
                                        <input type="text" name="bank_name" placeholder="Bank name" style="{{ $inp }}width:150px;">
                                        <input type="text" name="account_number" placeholder="Account number" style="{{ $inp }}width:150px;">
                                        <input type="text" name="ifsc_code" placeholder="IFSC" style="{{ $inp }}width:110px;text-transform:uppercase;">
                                        <select name="account_type" style="{{ $inp }}">
                                            <option value="">Account type</option>
                                            <option value="savings">Savings</option>
                                            <option value="current">Current</option>
                                            <option value="overdraft">Overdraft</option>
                                        </select>
                                        <input type="text" name="branch" placeholder="Branch" style="{{ $inp }}width:120px;">
                                    @elseif ($t === 'wallet')
                                        <input type="text" name="wallet_id" placeholder="Wallet ID / account" style="{{ $inp }}width:180px;">
                                    @endif
                                    <button type="submit" style="{{ $btn }}">Save {{ $label }}</button>
                                </form>
                            </details>
                        </div>
                    @endforeach
                </div>

                {{-- Opening balance per source --}}
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Opening balances</label>
                    <p style="color:#64748b;font-size:13px;margin-bottom:8px;">Pick where the money sits, then enter the balance as of go-live.</p>

                    {{-- CSV import --}}
                    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                        <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                            Header row: <code>payment_mode,amount</code> (mode = cash/bank/upi/wallet).
                            <a href="{{ route('onboarding.cash.template') }}" style="color:#b45309;">Download sample CSV</a>
                        </p>
                        <form method="POST" action="{{ route('onboarding.cash.import', $batch) }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                            <button type="submit" style="{{ $btn }}">Import balances</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('onboarding.cash.store', $batch) }}">
                        @csrf
                        {!! $sourceOptions('cash') !!}
                        <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount ₹" required style="{{ $inp }}width:130px;">
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>

                    {{-- Preview table with edit + delete --}}
                    @if ($cashRows->isNotEmpty())
                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
                            <thead>
                                <tr style="background:#f8fafc;text-align:left;">
                                    <th style="padding:8px;width:36px;">#</th>
                                    <th style="padding:8px;">Source</th>
                                    <th style="padding:8px;">Mode</th>
                                    <th style="padding:8px;text-align:right;">Amount ₹</th>
                                    <th style="padding:8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cashRows as $e)
                                    <tr style="border-top:1px solid #f1f5f9;">
                                        <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                        <td style="padding:8px;">{{ $e->payload['account_label'] ?? 'Cash in drawer' }}</td>
                                        <td style="padding:8px;color:#64748b;">{{ ucfirst($e->payload['payment_mode'] ?? 'cash') }}</td>
                                        <td style="padding:8px;text-align:right;">{{ number_format((float) ($e->payload['amount'] ?? 0), 2) }}</td>
                                        <td style="padding:8px;white-space:nowrap;">
                                            <details>
                                                <summary style="cursor:pointer;color:#b45309;display:inline;">Edit</summary>
                                                <form method="POST" action="{{ route('onboarding.cash.update', [$batch, $e]) }}" style="margin-top:6px;">
                                                    @csrf @method('PUT')
                                                    {!! $sourceOptions($e->payload['payment_method_id'] ?? 'cash') !!}
                                                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ $e->payload['amount'] ?? '' }}" required style="{{ $inp }}width:110px;">
                                                    <button type="submit" style="{{ $btn }}">Save</button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}" onsubmit="return confirm('Remove this balance?');" style="display:inline;">
                                                @csrf @method('DELETE')
                                                <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @endif

                {{-- ── Step: Opening finished stock ── --}}
                @if ($step === 'stock')
                @php
                    $stockRows  = $entries[$OE::KIND_STOCK_ITEM] ?? collect();
                    $normPurity = fn ($v) => ($v === null || $v === '') ? '' : rtrim(rtrim((string) $v, '0'), '.');
                    // Emit the full field set once; reused by the add form and each inline edit form.
                    $stockInputs = function ($e = null) use ($metals, $purityProfiles, $inp, $normPurity) {
                        $p = $e->payload ?? [];
                        $g = fn ($k) => isset($p[$k]) ? e($p[$k]) : '';
                        $h = '<select name="metal_type" style="'.$inp.'" required>';
                        foreach ($metals as $m) {
                            $s = (($p['metal_type'] ?? '') === $m) ? ' selected' : '';
                            $h .= '<option value="'.e($m).'"'.$s.'>'.e(ucfirst($m)).'</option>';
                        }
                        $h .= '</select>';
                        if ($purityProfiles->isEmpty()) {
                            $h .= '<input type="number" step="0.01" min="0" name="purity" value="'.$g('purity').'" placeholder="Purity" required style="'.$inp.'width:110px;">';
                        } else {
                            $sel = $normPurity($p['purity'] ?? null);
                            $h .= '<select name="purity" required style="'.$inp.'width:160px;"><option value="">Purity…</option>';
                            foreach ($purityProfiles as $metal => $profiles) {
                                $h .= '<optgroup label="'.e(ucfirst($metal)).'">';
                                foreach ($profiles as $pr) {
                                    $pv = $normPurity($pr->purity_value);
                                    $s  = ($sel !== '' && $sel === $pv) ? ' selected' : '';
                                    $h .= '<option value="'.e($pr->purity_value).'"'.$s.'>'.e($pr->label.' — '.$pv).'</option>';
                                }
                                $h .= '</optgroup>';
                            }
                            $h .= '</select>';
                        }
                        $h .= '<input type="number" step="0.001" min="0.001" name="gross_weight" value="'.$g('gross_weight').'" placeholder="Gross g" required style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.001" min="0" name="stone_weight" value="'.$g('stone_weight').'" placeholder="Stone g" style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.01" min="0" name="making_charges" value="'.$g('making_charges').'" placeholder="Making ₹" style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.01" min="0" name="stone_charges" value="'.$g('stone_charges').'" placeholder="Stone ₹" style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.01" min="0" name="cost_price" value="'.$g('cost_price').'" placeholder="Cost ₹" style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.01" min="0" name="selling_price" value="'.$g('selling_price').'" placeholder="Selling ₹" style="'.$inp.'width:90px;">';
                        $h .= '<input type="text" name="design" value="'.$g('design').'" placeholder="Design" style="'.$inp.'width:110px;">';
                        $h .= '<input type="text" name="category" value="'.$g('category').'" placeholder="Category" style="'.$inp.'width:110px;">';
                        $h .= '<input type="text" name="sub_category" value="'.$g('sub_category').'" placeholder="Sub-category" style="'.$inp.'width:110px;">';
                        $h .= '<input type="text" name="huid" value="'.$g('huid').'" placeholder="HUID" style="'.$inp.'width:110px;">';
                        $h .= '<input type="date" name="hallmark_date" value="'.$g('hallmark_date').'" title="Hallmark date" style="'.$inp.'width:150px;">';
                        $h .= '<input type="text" name="barcode" value="'.$g('barcode').'" placeholder="Barcode" style="'.$inp.'width:110px;">';
                        return $h;
                    };
                @endphp
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Opening finished stock</label>

                    {{-- CSV import --}}
                    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                        <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                            Header row: <code>metal_type,gross_weight,stone_weight,purity,making_charges,stone_charges,cost_price,selling_price,barcode,design,category,sub_category,huid</code>.
                            <a href="{{ route('onboarding.stock.template') }}" style="color:#b45309;">Download sample CSV</a>
                        </p>
                        <form method="POST" action="{{ route('onboarding.stock.import', $batch) }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                            <button type="submit" style="{{ $btn }}">Import items</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                        @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_STOCK_ITEM }}">
                        {!! $stockInputs() !!}
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>

                    {{-- Preview table with edit + delete --}}
                    @if ($stockRows->isNotEmpty())
                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
                            <thead>
                                <tr style="background:#f8fafc;text-align:left;">
                                    <th style="padding:8px;width:36px;">#</th>
                                    <th style="padding:8px;">Metal</th>
                                    <th style="padding:8px;text-align:right;">Gross</th>
                                    <th style="padding:8px;text-align:right;">Stone</th>
                                    <th style="padding:8px;text-align:right;">Purity</th>
                                    <th style="padding:8px;text-align:right;">Cost ₹</th>
                                    <th style="padding:8px;text-align:right;">Selling ₹</th>
                                    <th style="padding:8px;">Design</th>
                                    <th style="padding:8px;">HUID</th>
                                    <th style="padding:8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stockRows as $e)
                                    <tr style="border-top:1px solid #f1f5f9;">
                                        <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                        <td style="padding:8px;">{{ ucfirst($e->payload['metal_type'] ?? '') }}</td>
                                        <td style="padding:8px;text-align:right;">{{ $e->payload['gross_weight'] ?? '' }}</td>
                                        <td style="padding:8px;text-align:right;">{{ $e->payload['stone_weight'] ?? '—' }}</td>
                                        <td style="padding:8px;text-align:right;">{{ $normPurity($e->payload['purity'] ?? '') }}</td>
                                        <td style="padding:8px;text-align:right;">{{ isset($e->payload['cost_price']) ? number_format((float) $e->payload['cost_price'], 2) : '—' }}</td>
                                        <td style="padding:8px;text-align:right;">{{ isset($e->payload['selling_price']) ? number_format((float) $e->payload['selling_price'], 2) : '—' }}</td>
                                        <td style="padding:8px;">{{ $e->payload['design'] ?? '—' }}</td>
                                        <td style="padding:8px;">{{ $e->payload['huid'] ?? '—' }}</td>
                                        <td style="padding:8px;white-space:nowrap;">
                                            <details>
                                                <summary style="cursor:pointer;color:#b45309;display:inline;">Edit</summary>
                                                <form method="POST" action="{{ route('onboarding.stock.update', [$batch, $e]) }}" style="margin-top:6px;">
                                                    @csrf @method('PUT')
                                                    {!! $stockInputs($e) !!}
                                                    <button type="submit" style="{{ $btn }}">Save</button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}" onsubmit="return confirm('Remove this item?');" style="display:inline;">
                                                @csrf @method('DELETE')
                                                <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @endif

                {{-- ── Step: Vault metal ── --}}
                @if ($step === 'vault')
                @php
                    $vaultRows   = $entries[$OE::KIND_VAULT_METAL] ?? collect();
                    $normPurityV = fn ($v) => ($v === null || $v === '') ? '' : rtrim(rtrim((string) $v, '0'), '.');
                    // Vault is loose bullion → accounting metals only (gold/silver).
                    $vaultInputs = function ($e = null) use ($metals, $purityProfiles, $inp, $normPurityV) {
                        $p = $e->payload ?? [];
                        $g = fn ($k) => isset($p[$k]) ? e($p[$k]) : '';
                        $h = '<select name="metal_type" style="'.$inp.'" required>';
                        foreach ($metals as $m) {
                            $s = (($p['metal_type'] ?? '') === $m) ? ' selected' : '';
                            $h .= '<option value="'.e($m).'"'.$s.'>'.e(ucfirst($m)).'</option>';
                        }
                        $h .= '</select>';
                        if ($purityProfiles->isEmpty()) {
                            $h .= '<input type="number" step="0.01" min="0" name="purity" value="'.$g('purity').'" placeholder="Purity" required style="'.$inp.'width:110px;">';
                        } else {
                            $sel = $normPurityV($p['purity'] ?? null);
                            $h .= '<select name="purity" required style="'.$inp.'width:160px;"><option value="">Purity…</option>';
                            foreach ($purityProfiles as $metal => $profiles) {
                                $h .= '<optgroup label="'.e(ucfirst($metal)).'">';
                                foreach ($profiles as $pr) {
                                    $pv = $normPurityV($pr->purity_value);
                                    $s  = ($sel !== '' && $sel === $pv) ? ' selected' : '';
                                    $h .= '<option value="'.e($pr->purity_value).'"'.$s.'>'.e($pr->label.' — '.$pv).'</option>';
                                }
                                $h .= '</optgroup>';
                            }
                            $h .= '</select>';
                        }
                        $h .= '<input type="number" step="0.001" min="0.001" name="fine_weight" value="'.$g('fine_weight').'" placeholder="Fine g" required style="'.$inp.'width:90px;">';
                        $h .= '<input type="number" step="0.01" min="0" name="cost_per_fine_gram" value="'.$g('cost_per_fine_gram').'" placeholder="Cost/fine g ₹" style="'.$inp.'width:120px;">';
                        $h .= '<input type="text" name="notes" value="'.$g('notes').'" placeholder="Label / notes" style="'.$inp.'width:160px;">';
                        return $h;
                    };
                @endphp
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Vault / loose bullion</label>

                    {{-- CSV import --}}
                    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                        <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                            Header row: <code>metal_type,purity,fine_weight,cost_per_fine_gram,notes</code> (gold/silver only). Enter <strong>fine</strong> weight (pure content), not gross.
                            <a href="{{ route('onboarding.vault.template') }}" style="color:#b45309;">Download sample CSV</a>
                        </p>
                        <form method="POST" action="{{ route('onboarding.vault.import', $batch) }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                            <button type="submit" style="{{ $btn }}">Import lots</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('onboarding.entries.store', $batch) }}">
                        @csrf <input type="hidden" name="kind" value="{{ $OE::KIND_VAULT_METAL }}">
                        {!! $vaultInputs() !!}
                        <button type="submit" style="{{ $btn }}">Add</button>
                    </form>

                    {{-- Preview table with edit + delete --}}
                    @if ($vaultRows->isNotEmpty())
                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
                            <thead>
                                <tr style="background:#f8fafc;text-align:left;">
                                    <th style="padding:8px;width:36px;">#</th>
                                    <th style="padding:8px;">Metal</th>
                                    <th style="padding:8px;text-align:right;">Purity</th>
                                    <th style="padding:8px;text-align:right;">Fine g</th>
                                    <th style="padding:8px;text-align:right;">Cost/fine ₹</th>
                                    <th style="padding:8px;">Notes</th>
                                    <th style="padding:8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($vaultRows as $e)
                                    <tr style="border-top:1px solid #f1f5f9;">
                                        <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                        <td style="padding:8px;">{{ ucfirst($e->payload['metal_type'] ?? '') }}</td>
                                        <td style="padding:8px;text-align:right;">{{ $normPurityV($e->payload['purity'] ?? '') }}</td>
                                        <td style="padding:8px;text-align:right;">{{ $e->payload['fine_weight'] ?? '' }}</td>
                                        <td style="padding:8px;text-align:right;">{{ isset($e->payload['cost_per_fine_gram']) ? number_format((float) $e->payload['cost_per_fine_gram'], 2) : '—' }}</td>
                                        <td style="padding:8px;">{{ $e->payload['notes'] ?? '—' }}</td>
                                        <td style="padding:8px;white-space:nowrap;">
                                            <details>
                                                <summary style="cursor:pointer;color:#b45309;display:inline;">Edit</summary>
                                                <form method="POST" action="{{ route('onboarding.vault.update', [$batch, $e]) }}" style="margin-top:6px;">
                                                    @csrf @method('PUT')
                                                    {!! $vaultInputs($e) !!}
                                                    <button type="submit" style="{{ $btn }}">Save</button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}" onsubmit="return confirm('Remove this lot?');" style="display:inline;">
                                                @csrf @method('DELETE')
                                                <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @endif

                {{-- ── Step: Customer opening balances ── --}}
                @if ($step === 'balances')
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Customer opening balances</label>
                    @if ($customers->isEmpty())
                        <p style="color:#991b1b;font-size:13px;">Add customers first (Customers step) to record their balances.</p>
                    @else
                        {{-- CSV import --}}
                        <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                            <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                            <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                                Header row: <code>mobile,type,amount</code> (type = receivable/payable/advance/gold; gold → amount is fine grams). Customer must already exist.
                                <a href="{{ route('onboarding.balances.template') }}" style="color:#b45309;">Download sample CSV</a>
                            </p>
                            <form method="POST" action="{{ route('onboarding.balances.import', $batch) }}" enctype="multipart/form-data">
                                @csrf
                                <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                                <button type="submit" style="{{ $btn }}">Import balances</button>
                            </form>
                        </div>
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
                        @php
                            $custById     = $customers->keyBy('id');
                            $balanceTypes = [
                                $OE::KIND_CUSTOMER_RECEIVABLE => 'Owes shop',
                                $OE::KIND_CUSTOMER_PAYABLE    => 'Shop owes',
                                $OE::KIND_CUSTOMER_ADVANCE    => 'Advance',
                                $OE::KIND_CUSTOMER_GOLD       => 'Gold (fine g)',
                            ];
                            $balanceRows = collect(array_keys($balanceTypes))
                                ->flatMap(fn ($k) => $entries[$k] ?? collect());
                        @endphp
                        {{-- Preview table with edit + delete --}}
                        @if ($balanceRows->isNotEmpty())
                            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
                                <thead>
                                    <tr style="background:#f8fafc;text-align:left;">
                                        <th style="padding:8px;width:36px;">#</th>
                                        <th style="padding:8px;">Customer</th>
                                        <th style="padding:8px;">Mobile</th>
                                        <th style="padding:8px;">Type</th>
                                        <th style="padding:8px;text-align:right;">Value</th>
                                        <th style="padding:8px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($balanceRows as $e)
                                        @php
                                            $cust   = $custById[$e->payload['customer_id'] ?? null] ?? null;
                                            $isGold = $e->kind === $OE::KIND_CUSTOMER_GOLD;
                                        @endphp
                                        <tr style="border-top:1px solid #f1f5f9;">
                                            <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                            <td style="padding:8px;">{{ $cust ? trim($cust->first_name.' '.$cust->last_name) : '—' }}</td>
                                            <td style="padding:8px;color:#64748b;">{{ $cust->mobile ?? '—' }}</td>
                                            <td style="padding:8px;">{{ $balanceTypes[$e->kind] ?? $e->kind }}</td>
                                            <td style="padding:8px;text-align:right;">
                                                @if ($isGold)
                                                    {{ $e->payload['fine_gold'] ?? '' }} g
                                                @else
                                                    ₹ {{ number_format((float) ($e->payload['amount'] ?? 0), 2) }}
                                                @endif
                                            </td>
                                            <td style="padding:8px;white-space:nowrap;">
                                                <details>
                                                    <summary style="cursor:pointer;color:#b45309;display:inline;">Edit</summary>
                                                    <form method="POST" action="{{ route('onboarding.balances.update', [$batch, $e]) }}" style="margin-top:6px;">
                                                        @csrf @method('PUT')
                                                        <select name="customer_id" style="{{ $inp }}" required>
                                                            @foreach ($customers as $c)
                                                                <option value="{{ $c->id }}" @selected(($e->payload['customer_id'] ?? null) == $c->id)>{{ $customerLabel($c) }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if ($isGold)
                                                            <input type="number" step="0.001" name="fine_gold" value="{{ $e->payload['fine_gold'] ?? '' }}" placeholder="Fine g" required style="{{ $inp }}width:110px;">
                                                        @else
                                                            <input type="number" step="0.01" min="0.01" name="amount" value="{{ $e->payload['amount'] ?? '' }}" placeholder="₹" required style="{{ $inp }}width:110px;">
                                                        @endif
                                                        <button type="submit" style="{{ $btn }}">Save</button>
                                                    </form>
                                                </details>
                                                <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}" onsubmit="return confirm('Remove this balance?');" style="display:inline;">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    @endif
                </div>
                @endif

                {{-- ── Step: Suppliers & karigars ── --}}
                @if ($step === 'suppliers')
                <div class="onboarding-step-shell" style="{{ $card }}">
                    <label style="{{ $lbl }}">Suppliers & karigars</label>

                    {{-- CSV import --}}
                    <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Bulk import (CSV)</p>
                        <p style="color:#64748b;font-size:13px;margin-bottom:8px;">
                            Header row: <code>type,name,amount,metal_type,purity,fine_weight</code> (type = supplier_payable/supplier_receivable/karigar_money/karigar_gold; metal columns for karigar_gold only). Supplier/karigar must already exist.
                            <a href="{{ route('onboarding.suppliers.template') }}" style="color:#b45309;">Download sample CSV</a>
                        </p>
                        <form method="POST" action="{{ route('onboarding.suppliers.import', $batch) }}" enctype="multipart/form-data">
                            @csrf
                            <input type="file" name="file" accept=".csv,.txt" required style="{{ $inp }}">
                            <button type="submit" style="{{ $btn }}">Import rows</button>
                        </form>
                    </div>

                    {{-- Add supplier to directory --}}
                    <div style="margin-bottom:12px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Add a supplier</p>
                        <form method="POST" action="{{ route('onboarding.vendor.store', $batch) }}">
                            @csrf
                            <input type="text" name="name" placeholder="Supplier name *" required value="{{ old('name') }}" style="{{ $inp }}width:160px;">
                            <input type="text" name="contact_person" placeholder="Contact person" style="{{ $inp }}width:140px;">
                            <input type="text" name="mobile" placeholder="Mobile" style="{{ $inp }}width:120px;">
                            <input type="email" name="email" placeholder="Email" style="{{ $inp }}width:160px;">
                            <input type="text" name="gst_number" placeholder="GSTIN" style="{{ $inp }}width:150px;">
                            <input type="text" name="address" placeholder="Address" style="{{ $inp }}width:180px;">
                            <button type="submit" style="{{ $btn }}">Add supplier</button>
                        </form>
                        @if ($vendors->isNotEmpty())
                            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
                                <thead>
                                    <tr style="background:#f8fafc;text-align:left;">
                                        <th style="padding:6px 8px;width:36px;">#</th>
                                        <th style="padding:6px 8px;">Supplier</th>
                                        <th style="padding:6px 8px;">Contact</th>
                                        <th style="padding:6px 8px;">Mobile</th>
                                        <th style="padding:6px 8px;">GSTIN</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($vendors as $v)
                                        <tr style="border-top:1px solid #f1f5f9;">
                                            <td style="padding:6px 8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                            <td style="padding:6px 8px;">{{ $v->name }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $v->contact_person ?? '—' }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $v->mobile ?? '—' }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $v->gst_number ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

                    {{-- Add karigar to directory --}}
                    <div style="margin-bottom:16px;">
                        <p style="font-weight:600;font-size:14px;margin-bottom:6px;">Add a karigar</p>
                        <form method="POST" action="{{ route('onboarding.karigar.store', $batch) }}">
                            @csrf
                            <input type="text" name="name" placeholder="Karigar name *" required style="{{ $inp }}width:160px;">
                            <input type="text" name="shop_name" placeholder="Workshop name" style="{{ $inp }}width:150px;">
                            <input type="text" name="contact_person" placeholder="Contact person" style="{{ $inp }}width:140px;">
                            <input type="text" name="mobile" placeholder="Mobile" style="{{ $inp }}width:120px;">
                            <button type="submit" style="{{ $btn }}">Add karigar</button>
                        </form>
                        @if ($karigars->isNotEmpty())
                            <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
                                <thead>
                                    <tr style="background:#f8fafc;text-align:left;">
                                        <th style="padding:6px 8px;width:36px;">#</th>
                                        <th style="padding:6px 8px;">Karigar</th>
                                        <th style="padding:6px 8px;">Workshop</th>
                                        <th style="padding:6px 8px;">Contact</th>
                                        <th style="padding:6px 8px;">Mobile</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($karigars as $k)
                                        <tr style="border-top:1px solid #f1f5f9;">
                                            <td style="padding:6px 8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                            <td style="padding:6px 8px;">{{ $k->name }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $k->shop_name ?? '—' }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $k->contact_person ?? '—' }}</td>
                                            <td style="padding:6px 8px;color:#64748b;">{{ $k->mobile ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

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
                                @foreach ($accountingMetals as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                            </select>
                            <input type="number" step="0.01" name="purity" placeholder="Purity" required style="{{ $inp }}width:80px;">
                            <input type="number" step="0.001" name="fine_weight" placeholder="Fine g" required style="{{ $inp }}width:90px;">
                            <button type="submit" style="{{ $btn }}">Add</button>
                        </form>
                    @endif
                    @php
                        $vendById = $vendors->keyBy('id');
                        $karById  = $karigars->keyBy('id');
                        $supTypes = [
                            $OE::KIND_SUPPLIER_PAYABLE    => 'Shop owes supplier',
                            $OE::KIND_SUPPLIER_RECEIVABLE => 'Supplier owes shop',
                            $OE::KIND_KARIGAR_MONEY       => 'Karigar money',
                            $OE::KIND_KARIGAR_GOLD        => 'Karigar-held gold',
                        ];
                        $supRows = collect(array_keys($supTypes))->flatMap(fn ($k) => $entries[$k] ?? collect());
                    @endphp
                    {{-- Preview table --}}
                    @if ($supRows->isNotEmpty())
                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
                            <thead>
                                <tr style="background:#f8fafc;text-align:left;">
                                    <th style="padding:8px;width:36px;">#</th>
                                    <th style="padding:8px;">Party</th>
                                    <th style="padding:8px;">Type</th>
                                    <th style="padding:8px;text-align:right;">Value</th>
                                    <th style="padding:8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($supRows as $e)
                                    @php
                                        $isKar  = in_array($e->kind, [$OE::KIND_KARIGAR_MONEY, $OE::KIND_KARIGAR_GOLD], true);
                                        $party  = $isKar
                                            ? ($karById[$e->payload['karigar_id'] ?? null]->name ?? '—')
                                            : ($vendById[$e->payload['vendor_id'] ?? null]->name ?? '—');
                                        $isGold = $e->kind === $OE::KIND_KARIGAR_GOLD;
                                    @endphp
                                    <tr style="border-top:1px solid #f1f5f9;">
                                        <td style="padding:8px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                        <td style="padding:8px;">{{ $party }}</td>
                                        <td style="padding:8px;">{{ $supTypes[$e->kind] ?? $e->kind }}</td>
                                        <td style="padding:8px;text-align:right;">
                                            @if ($isGold)
                                                {{ $e->payload['fine_weight'] ?? '' }} g ({{ ucfirst($e->payload['metal_type'] ?? '') }})
                                            @else
                                                ₹ {{ number_format((float) ($e->payload['amount'] ?? 0), 2) }}
                                            @endif
                                        </td>
                                        <td style="padding:8px;white-space:nowrap;">
                                            <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}" onsubmit="return confirm('Remove this row?');" style="display:inline;">
                                                @csrf @method('DELETE')
                                                <button type="submit" style="padding:4px 10px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:12px;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @endif

                {{-- ── Step: Review / reconcile + Lock ── --}}
                @if ($step === 'review')
                @php
                    // "What you've added" — counts pulled from already-loaded data, no query.
                    $editLink = fn ($s) => route('onboarding.index', ['step' => $s]);
                    $coverage = [
                        ['Customers', $customers->count(), 'customers'],
                        ['Bank / UPI / wallet accounts', $paymentMethods->flatten()->count(), 'cash'],
                        ['Cash / balance rows', ($entries[$OE::KIND_CASH] ?? collect())->count(), 'cash'],
                        ['Opening stock items', ($entries[$OE::KIND_STOCK_ITEM] ?? collect())->count(), 'stock'],
                        ['Vault metal lots', ($entries[$OE::KIND_VAULT_METAL] ?? collect())->count(), 'vault'],
                        ['Customer balance rows', collect([$OE::KIND_CUSTOMER_RECEIVABLE, $OE::KIND_CUSTOMER_PAYABLE, $OE::KIND_CUSTOMER_ADVANCE, $OE::KIND_CUSTOMER_GOLD])->sum(fn ($k) => ($entries[$k] ?? collect())->count()), 'balances'],
                        ['Suppliers', $vendors->count(), 'suppliers'],
                        ['Karigars', $karigars->count(), 'suppliers'],
                    ];
                @endphp

                {{-- Coverage: what's been added --}}
                <div style="{{ $card }}">
                    <label style="{{ $lbl }}">What you've added</label>
                    <table style="width:100%;border-collapse:collapse;font-size:14px;">
                        @foreach ($coverage as [$label, $count, $slug])
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:6px 4px;">{{ $label }}</td>
                                <td style="padding:6px 4px;text-align:right;font-weight:600;">{{ $count }}</td>
                                <td style="padding:6px 4px;text-align:right;"><a href="{{ $editLink($slug) }}" style="color:#b45309;font-size:13px;">Edit</a></td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                <div style="{{ $card }}background:#fffbeb;">
                    <label style="{{ $lbl }}">Review & reconcile</label>
                    <table style="width:100%;border-collapse:collapse;font-size:14px;">
                        <tr><td style="padding:4px;">Vault fine (g)</td><td style="text-align:right;">{{ number_format($vaultFine, 3) }}</td><td style="text-align:right;"><a href="{{ $editLink('vault') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Karigar-held fine (g)</td><td style="text-align:right;">{{ number_format($karigarHeldFine, 3) }}</td><td style="text-align:right;"><a href="{{ $editLink('suppliers') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Customer gold fine (g)</td><td style="text-align:right;">{{ number_format($customerGoldFine, 3) }}</td><td style="text-align:right;"><a href="{{ $editLink('balances') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Opening stock fine (g)</td><td style="text-align:right;">{{ number_format($stockFine, 3) }}</td><td style="text-align:right;"><a href="{{ $editLink('stock') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr style="border-top:1px solid #d1d5db;font-weight:700;"><td style="padding:4px;">Total fine (g)</td><td style="text-align:right;">{{ number_format($vaultFine + $karigarHeldFine + $customerGoldFine + $stockFine, 3) }}</td><td></td></tr>
                        <tr><td colspan="3" style="padding-top:8px;"></td></tr>
                        @foreach ($cashByMode as $mode => $amt)
                            <tr><td style="padding:4px;">Cash — {{ ucfirst($mode) }}</td><td style="text-align:right;">₹{{ number_format($amt, 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('cash') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        @endforeach
                        <tr><td style="padding:4px;">Customer receivable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_RECEIVABLE, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('balances') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Customer payable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_PAYABLE, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('balances') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Customer advances</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_CUSTOMER_ADVANCE, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('balances') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Supplier payable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_SUPPLIER_PAYABLE, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('suppliers') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Supplier receivable</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_SUPPLIER_RECEIVABLE, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('suppliers') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                        <tr><td style="padding:4px;">Karigar money</td><td style="text-align:right;">₹{{ number_format($sum($OE::KIND_KARIGAR_MONEY, 'amount'), 2) }}</td><td style="text-align:right;"><a href="{{ $editLink('suppliers') }}" style="color:#b45309;font-size:13px;">Edit</a></td></tr>
                    </table>
                    <p style="color:#92400e;font-size:13px;margin-top:8px;">Reconcile these against your physical count before locking. Locking is final — post-lock changes are adjustment entries in the live ledgers.</p>
                </div>

                {{-- Lock --}}
                <div style="display:flex;gap:12px;margin-bottom:24px;">
                    <form method="POST" action="{{ route('onboarding.lock', $batch) }}" onsubmit="return confirm('Lock is final. Post all opening balances?');">
                        @csrf <button type="submit" style="{{ $btn }}">Lock &amp; post opening balances</button>
                    </form>
                    <form method="POST" action="{{ route('onboarding.cancel', $batch) }}" onsubmit="return confirm('Discard this onboarding batch?');">
                        @csrf <button type="submit" style="padding:8px 16px;background:#fff;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;cursor:pointer;">Cancel batch</button>
                    </form>
                </div>
                @endif

                {{-- Back / Save & continue nav (Lock lives on the review step itself) --}}
                <div style="display:flex;gap:12px;justify-content:space-between;margin-bottom:24px;">
                    @if ($prev)
                        <a href="{{ route('onboarding.index', ['step' => $prev]) }}" style="{{ $btnGhost }}">← Back</a>
                    @else
                        <span></span>
                    @endif
                    @if ($next)
                        <a href="{{ route('onboarding.index', ['step' => $next]) }}" style="{{ $btn }}">Save &amp; continue →</a>
                    @endif
                </div>
            @else
                <div style="{{ $card }}">
                    <p style="color:#065f46;">This batch is {{ $batch->status }} — opening balances are being posted.</p>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
