<x-app-layout>
    <style>
        .dhiran-activate-root {
            --da-ink: #0f172a;
            --da-muted: #64748b;
            --da-gold: #f4a300;
            --da-gold-deep: #d98b00;
            --da-line: #d7dee8;
            --da-card: #ffffff;
            --da-shadow: 0 10px 24px rgba(20, 40, 75, 0.08);
            padding-top: 18px;
        }
        .da-hero {
            text-align: center;
            padding: 48px 24px 32px;
        }
        .da-hero-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #fff7e8 0%, #fef3cd 100%);
            margin-bottom: 20px;
        }
        .da-hero-icon svg {
            width: 36px;
            height: 36px;
            color: var(--da-gold-deep);
        }
        .da-hero-title {
            font-size: clamp(22px, 3vw, 30px);
            font-weight: 800;
            color: var(--da-ink);
            margin-bottom: 8px;
        }
        .da-hero-subtitle {
            font-size: 15px;
            color: var(--da-muted);
            max-width: 520px;
            margin: 0 auto 32px;
            line-height: 1.6;
        }
        .da-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            max-width: 800px;
            margin: 0 auto 36px;
        }
        .da-feature-card {
            background: var(--da-card);
            border: 1px solid var(--da-line);
            border-radius: 14px;
            padding: 20px;
            text-align: left;
            box-shadow: var(--da-shadow);
        }
        .da-feature-card-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            margin-bottom: 12px;
        }
        .da-feature-card-icon svg {
            width: 20px;
            height: 20px;
        }
        .da-feature-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--da-ink);
            margin-bottom: 4px;
        }
        .da-feature-card-desc {
            font-size: 12px;
            color: var(--da-muted);
            line-height: 1.5;
        }
        .da-activate-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--da-gold) 0%, var(--da-gold-deep) 100%);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(244, 163, 0, 0.35);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .da-activate-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(244, 163, 0, 0.45);
        }
        .da-activate-btn svg {
            width: 18px;
            height: 18px;
        }
    </style>

    <x-page-header>
        <h1 class="page-title">Dhiran (Gold Loan)</h1>
        <div class="page-actions">
            <span class="header-badge">Module Setup</span>
        </div>
    </x-page-header>

    <div class="content-inner dhiran-activate-root">
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="da-hero">
                <div class="da-hero-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </div>
                <h2 class="da-hero-title">Dhiran &mdash; Gold Loan / Girvi Module</h2>
                <p class="da-hero-subtitle">
                    Manage gold pledge loans (Girvi) directly from JewelFlow. Track collateral, calculate interest, handle renewals and forfeitures &mdash; all in one place.
                </p>

                <div class="da-features">
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-amber-100 text-amber-700">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33"/></svg>
                        </div>
                        <div class="da-feature-card-title">Gold Pledge Loans</div>
                        <div class="da-feature-card-desc">Issue loans against gold jewellery with configurable LTV ratios and interest rates.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-emerald-100 text-emerald-700">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="da-feature-card-title">Interest Tracking</div>
                        <div class="da-feature-card-desc">Flat, daily or compound interest calculations with automatic penalty tracking on overdue loans.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-sky-100 text-sky-700">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                        <div class="da-feature-card-title">Collateral Management</div>
                        <div class="da-feature-card-desc">Detailed item-wise tracking with weight, purity, HUID and individual item release support.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-violet-100 text-violet-700">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div class="da-feature-card-title">Reports & Receipts</div>
                        <div class="da-feature-card-desc">Active loan reports, overdue tracking, interest summaries and printable pledge receipts.</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('dhiran.activate') }}">
                    @csrf
                    <button type="submit" class="da-activate-btn">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Activate Dhiran Module
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
