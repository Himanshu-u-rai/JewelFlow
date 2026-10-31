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
                    <x-icon name="circle-dollar-sign" stroke-width="1.5" />
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
                <p class="da-feature-card-desc">{{ \App\Support\NotAvailable::MESSAGE }}</p>
                @endcan
            </div>
        </div>
    </div>
</x-dhiran-layout>
