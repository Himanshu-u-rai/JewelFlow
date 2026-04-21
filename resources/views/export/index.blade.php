<x-app-layout>
<style>
    .export-page {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .export-header {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .export-sub {
        font-size: 13px;
        color: #64748b;
        margin: 0;
    }

    .export-alert {
        padding: 10px 14px;
        border-radius: 16px;
        font-size: 13px;
        border: 1px solid transparent;
    }

    .export-alert.success {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #065f46;
    }

    .export-alert.error {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .export-panel {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        padding: 18px;
        box-shadow: 0 16px 30px rgba(15, 23, 42, 0.06);
    }

    .export-intro {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
    }

    .export-intro-title {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 4px 0;
    }

    .export-intro-desc {
        font-size: 13px;
        color: #64748b;
        margin: 0;
    }

    .export-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        color: #0f766e;
        background: #e0f2f1;
        border-radius: 9999px;
        padding: 4px 10px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .export-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }

    .export-card {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .export-card:hover {
        transform: translateY(-1px);
        border-color: rgba(15, 118, 110, 0.3);
        box-shadow: 0 14px 26px rgba(15, 23, 42, 0.08);
    }

    .export-card.highlight {
        background: #f8fafc;
        border-color: rgba(59, 130, 246, 0.2);
    }

    .export-card-header {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .export-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .export-icon svg {
        width: 22px;
        height: 22px;
    }

    .export-card-title {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .export-card-desc {
        font-size: 13px;
        color: #64748b;
        margin: 0;
    }

    .export-card form {
        margin-top: auto;
    }

    .export-btn {
        width: 100%;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        background: #0f766e;
        color: #ffffff;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.15s ease;
    }

    .export-btn:hover {
        background: #0b5f5d;
        transform: translateY(-1px);
    }

    .export-note {
        margin-top: 16px;
        background: #f8fafc;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-left: 4px solid #0f766e;
        border-radius: 16px;
        padding: 12px 14px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        color: #475569;
        font-size: 13px;
    }

    .export-note svg {
        width: 18px;
        height: 18px;
        color: #0f766e;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .tone-blue .export-icon {
        background: #dbeafe;
        color: #2563eb;
    }

    .tone-purple .export-icon {
        background: #ede9fe;
        color: #0d9488;
    }

    .tone-green .export-icon {
        background: #dcfce7;
        color: #16a34a;
    }

    .tone-amber .export-icon {
        background: #fef3c7;
        color: #d97706;
    }

    .tone-red .export-icon {
        background: #fee2e2;
        color: #dc2626;
    }

    .tone-amber .export-icon {
        background: #ccfbf1;
        color: #0d9488;
    }

    @media (max-width: 640px) {
        .export-panel {
            padding: 14px;
        }

        .export-intro {
            flex-direction: column;
            align-items: flex-start;
        }

        .export-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<x-page-header
    class="ops-treatment-header"
    :title="__('Export Data')"
    subtitle="Download shop data in CSV format for backup or analysis."
/>

<div class="content-inner ops-treatment-page">
    <div class="export-page">
        <x-app-alerts />

        <div class="export-panel">
            <div class="export-intro">
                <div>
                    <h2 class="export-intro-title">Choose a dataset to export</h2>
                    <p class="export-intro-desc">Each export downloads a clean CSV file with your shop’s data only.</p>
                </div>
            </div>

            <div class="export-grid">
                <div class="export-card tone-blue">
                    <div class="export-card-header">
                        <div class="export-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                        <h3 class="export-card-title">Customers</h3>
                    </div>
                    <p class="export-card-desc">Customer profiles, contact details, and gold balances.</p>
                    <form action="{{ route('export.customers') }}" method="POST" data-turbo="false">
                        @csrf
                        <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Customers</button>
                    </form>
                </div>

                <div class="export-card tone-purple">
                    <div class="export-card-header">
                        <div class="export-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <h3 class="export-card-title">Products</h3>
                    </div>
                    <p class="export-card-desc">Jewellery products, categories, and pricing details.</p>
                    <form action="{{ route('export.products') }}" method="POST" data-turbo="false">
                        @csrf
                        <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Products</button>
                    </form>
                </div>

                <div class="export-card tone-green">
                    <div class="export-card-header">
                        <div class="export-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="export-card-title">Sales & Invoices</h3>
                    </div>
                    <p class="export-card-desc">Sales transactions and invoice history.</p>
                    <form action="{{ route('export.invoices') }}" method="POST" data-turbo="false">
                        @csrf
                        <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Sales</button>
                    </form>
                </div>

                <div class="export-card tone-amber">
                    <div class="export-card-header">
                        <div class="export-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 class="export-card-title">Metal Ledger</h3>
                    </div>
                    <p class="export-card-desc">Incoming old-metal payments received during sales (gold/silver).</p>
                    <form action="{{ route('export.gold-ledger') }}" method="POST" data-turbo="false">
                        @csrf
                        <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Metal Data</button>
                    </form>
                </div>

                <div class="export-card tone-red">
                    <div class="export-card-header">
                        <div class="export-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                        <h3 class="export-card-title">Cash Transactions</h3>
                    </div>
                    <p class="export-card-desc">Cash in/out transactions and payment records.</p>
                    <form action="{{ route('export.cash-transactions') }}" method="POST" data-turbo="false">
                        @csrf
                        <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Cash Data</button>
                    </form>
                </div>

                @if(auth()->user()->shop?->isManufacturer())
                    <div class="export-card tone-amber highlight">
                        <div class="export-card-header">
                            <div class="export-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </div>
                            <h3 class="export-card-title">Complete Backup</h3>
                        </div>
                        <p class="export-card-desc">Full shop export in a single comprehensive file.</p>
                        <form action="{{ route('export.all') }}" method="POST" data-turbo="false">
                            @csrf
                            <button type="submit" class="export-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export All Data</button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="export-note">
                <svg fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <p><strong>Note:</strong> All exports include only your shop's data. Files are downloaded in CSV format and open in Microsoft Excel, Google Sheets, and other spreadsheet applications.</p>
            </div>
        </div>
    </div>
</div>
</x-app-layout>
