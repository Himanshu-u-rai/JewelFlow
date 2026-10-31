<x-dhiran-layout title="Activate Dhiran">
    <x-dhiran.page-header>
        <h1 class="page-title">Dhiran (Pledge Loans)</h1>
        <div class="page-actions">
            <span class="header-badge">Module Setup</span>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-activate-root">
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="da-hero">
                <div class="da-hero-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </div>
                <h2 class="da-hero-title">Dhiran &mdash; Pledge Loan Module</h2>
                <p class="da-hero-subtitle">
                    Manage gold &amp; silver pledge loans directly from JewelFlows. Track collateral, calculate interest, handle renewals and forfeitures &mdash; all in one place.
                </p>

                <div class="da-features">
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-amber-100 text-amber-700">
                            <x-icon name="dollar-sign" />
                        </div>
                        <div class="da-feature-card-title">Gold &amp; Silver Pledge Loans</div>
                        <div class="da-feature-card-desc">Issue loans against gold &amp; silver jewellery with configurable LTV ratios and interest rates.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-emerald-100 text-emerald-700">
                            <x-icon name="calculator" />
                        </div>
                        <div class="da-feature-card-title">Interest Tracking</div>
                        <div class="da-feature-card-desc">Flat, daily or compound interest calculations with automatic penalty tracking on overdue loans.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-sky-100 text-sky-700">
                            <x-icon name="package" />
                        </div>
                        <div class="da-feature-card-title">Collateral Management</div>
                        <div class="da-feature-card-desc">Detailed item-wise tracking with weight, purity, HUID and individual item release support.</div>
                    </div>
                    <div class="da-feature-card">
                        <div class="da-feature-card-icon bg-violet-100 text-violet-700">
                            <x-icon name="file-chart-column" />
                        </div>
                        <div class="da-feature-card-title">Reports & Receipts</div>
                        <div class="da-feature-card-desc">Active loan reports, overdue tracking, interest summaries and printable pledge receipts.</div>
                    </div>
                </div>

                @can('dhiran.settings')
                <form method="POST" action="{{ route('dhiran.activate') }}" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="da-activate-btn">
                        <x-icon name="zap" />
                        Activate Dhiran Module
                    </button>
                </form>
                @else
                <p class="da-feature-card-desc">Only an owner/admin can activate the Dhiran module.</p>
                @endcan
            </div>
        </div>
    </div>
</x-dhiran-layout>
