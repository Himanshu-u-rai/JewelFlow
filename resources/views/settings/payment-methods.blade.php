<x-app-layout>
<style>
    [x-cloak] { display: none !important; }
    .settings-shell {
        padding: 0 clamp(12px, 2vw, 24px) 24px !important;
    }
    .settings-layout {
        display: grid;
        grid-template-columns: minmax(210px, 240px) minmax(0, 1fr);
        gap: clamp(12px, 1.6vw, 18px);
        align-items: start;
        min-height: calc(100vh - 140px);
    }
    .settings-nav {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        height: fit-content;
        position: sticky;
        top: 92px;
        box-shadow: 0 14px 24px rgba(15, 23, 42, 0.05);
    }
    .nav-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        border-radius: 12px;
        transition: all 0.15s;
    }
    .nav-item:hover { background: #f1f5f9; color: #0f172a; }
    .nav-item.active {
        background: #0f766e;
        color: #ffffff;
        box-shadow: 0 10px 18px rgba(15, 118, 110, 0.2);
        border-radius: 9999px;
    }
    .nav-item.active .nav-icon { background: rgba(255,255,255,0.2); color: #ffffff; }
    .nav-icon {
        font-size: 14px; width: 24px; height: 24px; border-radius: 8px;
        background: #f1f5f9; display: inline-flex; align-items: center;
        justify-content: center; color: #64748b; flex-shrink: 0;
    }
    .settings-content {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 20px;
        padding: clamp(16px, 2vw, 28px);
        min-width: 0;
        box-shadow: 0 18px 30px rgba(15, 23, 42, 0.06);
    }
    .pm-page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    .pm-eyebrow {
        margin: 0 0 6px;
        font-size: 11px;
        font-weight: 800;
        color: #b45309;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }
    .pm-page-title {
        font-size: clamp(18px, 2vw, 24px);
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 6px;
        line-height: 1.15;
    }
    .pm-page-subtitle {
        max-width: 680px;
        font-size: 13px;
        color: #64748b;
        line-height: 1.5;
        margin: 0;
    }
    .pm-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(92px, 1fr));
        gap: 8px;
        min-width: 210px;
    }
    .pm-summary-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 10px 12px;
        background: #f8fafc;
    }
    .pm-summary-label {
        display: block;
        font-size: 10px;
        font-weight: 800;
        color: #64748b;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .pm-summary-value {
        display: block;
        margin-top: 4px;
        font-size: 20px;
        font-weight: 900;
        color: #10173a;
        line-height: 1;
    }
    .pm-list {
        display: grid;
        gap: 16px;
    }
    .pm-section {
        border: 1px solid #dde5ef;
        border-radius: 18px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
        overflow: hidden;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.05);
    }
    .pm-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #e2e8f0;
        background: #ffffff;
    }
    .pm-section-heading {
        min-width: 0;
    }
    .pm-section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
    }
    .pm-type-mark {
        width: 30px;
        height: 30px;
        border-radius: 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff7ed;
        color: #c2410c;
        border: 1px solid #fed7aa;
        flex: 0 0 auto;
    }
    .pm-section-meta {
        margin: 2px 0 0 38px;
        font-size: 12px;
        color: #64748b;
    }
    .pm-section-body {
        padding: 14px;
    }
    .pm-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px;
        margin-bottom: 10px;
        background: #ffffff;
    }
    .pm-card-row {
        display: grid;
        grid-template-columns: minmax(160px, 1.1fr) minmax(180px, 1.4fr) auto;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .pm-card-name {
        min-width: 0;
        font-size: 14px;
        font-weight: 800;
        color: #1e293b;
        overflow-wrap: anywhere;
    }
    .pm-card-detail {
        min-width: 0;
        font-size: 13px;
        color: #64748b;
        overflow-wrap: anywhere;
    }
    .pm-method-actions {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: nowrap;
    }
    .pm-empty {
        font-size: 13px;
        color: #94a3b8;
        margin: 0 0 8px;
        padding: 14px;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #ffffff;
    }
    .pm-form {
        background: #f0fdfa;
        border: 1px solid #99f6e4;
        border-radius: 14px;
        padding: 16px;
        margin-top: 10px;
    }
    .pm-form-title {
        font-size: 12px;
        font-weight: 800;
        color: #0f766e;
        margin: 0 0 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .pm-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    .pm-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }
    .pm-form-label {
        font-size: 12px;
        font-weight: 800;
        color: #475569;
    }
    .pm-form-input {
        min-height: 42px;
        padding: 9px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 11px;
        font-size: 14px;
        color: #1e293b;
        background: #fff;
        width: 100%;
        max-width: 100%;
        min-width: 0;
    }
    .pm-form-input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    .pm-form-select {
        min-height: 42px;
        padding: 9px 34px 9px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 11px;
        font-size: 14px;
        color: #1e293b;
        background: #fff;
        width: 100%;
        max-width: 100%;
    }
    .pm-form-select:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13,148,136,0.15); }
    .pm-form-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .pm-toggle {
        position: relative; display: inline-flex; align-items: center;
        width: 36px; height: 20px; border-radius: 9999px;
        background: #cbd5e1; border: none; cursor: pointer;
        transition: background 0.2s; flex-shrink: 0; padding: 0;
    }
    .pm-toggle.pm-toggle-on { background: #0d9488; }
    .pm-toggle-thumb {
        position: absolute; left: 2px; width: 16px; height: 16px;
        border-radius: 9999px; background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        transition: transform 0.2s;
    }
    .pm-toggle.pm-toggle-on .pm-toggle-thumb { transform: translateX(16px); }

    .btn-add {
        flex: 0 0 auto;
        font-size: 12px;
        font-weight: 800;
        color: #0d9488;
        background: #f0fdfa;
        border: 1px solid #99f6e4;
        padding: 8px 14px;
        border-radius: 999px;
        cursor: pointer;
        transition: all 0.15s;
        box-shadow: 0 8px 18px rgba(13, 148, 136, 0.08);
    }
    .btn-add:hover { background: #ccfbf1; transform: translateY(-1px); }
    .btn-save {
        font-size: 13px;
        font-weight: 800;
        color: #fff;
        background: #0d9488;
        border: none;
        padding: 10px 18px;
        border-radius: 11px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-save:hover { background: #0f766e; }
    .btn-cancel {
        font-size: 13px;
        font-weight: 800;
        color: #64748b;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 10px 18px;
        border-radius: 11px;
        cursor: pointer;
    }
    .btn-icon {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        padding: 0;
        border-radius: 10px;
        transition: background 0.15s, border-color 0.15s;
    }
    .btn-icon:hover { background: #e2e8f0; }
    .btn-icon-danger:hover { background: #fee2e2; color: #dc2626; }
    .edit-form {
        background: #ffffff;
        border: 1px dashed #94a3b8;
        border-radius: 14px;
        padding: 14px;
        margin-top: 12px;
    }

    @media (max-width: 1100px) {
        .settings-layout { grid-template-columns: 1fr; }
        .settings-nav {
            position: static;
            flex-direction: row;
            overflow-x: auto;
            scrollbar-width: none;
            border-radius: 16px;
        }
        .settings-nav::-webkit-scrollbar { display: none; }
        .nav-item {
            white-space: nowrap;
            flex: 0 0 auto;
        }
    }

    @media (max-width: 768px) {
        .settings-shell { padding-inline: 10px !important; }
        .settings-content { border-radius: 18px; }
        .pm-page-head {
            flex-direction: column;
            gap: 12px;
        }
        .pm-summary {
            width: 100%;
            min-width: 0;
        }
        .pm-card-row {
            grid-template-columns: 1fr;
            align-items: stretch;
            gap: 8px;
        }
        .pm-method-actions {
            justify-content: space-between;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }
        .pm-section-header {
            align-items: flex-start;
            padding: 12px;
        }
        .pm-section-meta { margin-left: 38px; }
        .pm-section-body { padding: 12px; }
        .pm-form-grid { grid-template-columns: 1fr; }
        .pm-form-actions {
            display: grid;
            grid-template-columns: 1fr;
        }
        .btn-save,
        .btn-cancel {
            width: 100%;
        }
        .edit-form,
        .pm-form {
            padding: 12px;
        }
    }

    @media (max-width: 430px) {
        .settings-shell { padding-inline: 8px !important; }
        .settings-nav {
            margin-inline: -2px;
            padding: 8px;
        }
        .nav-item {
            padding: 8px 10px;
            font-size: 12px;
        }
        .settings-content {
            padding: 12px;
            border-radius: 16px;
        }
        .pm-summary-value { font-size: 18px; }
        .pm-section-header {
            flex-direction: column;
        }
        .btn-add { width: 100%; }
    }
</style>

<x-page-header title="{{ __('Settings') }}" />

<div class="content-inner settings-shell">
    <x-app-alerts class="mb-4" />

    <div class="settings-layout">
        {{-- Sidebar nav (mirrors settings.blade.php) --}}
        <nav class="settings-nav">
            <a href="{{ route('settings.edit', ['tab' => 'general']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> {{ __('General') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'shop']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> {{ __('Shop Info') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'rules']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></span> {{ __('Sale Rules') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'billing']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span> {{ __('Invoice') }}
            </a>
            <a href="{{ route('settings.payment-methods.index') }}" class="nav-item active">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span> {{ __('Payment Methods') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'preferences']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span> {{ __('Preferences') }}
            </a>
            @if(auth()->user()->shop?->isRetailer())
            <a href="{{ route('settings.edit', ['tab' => 'pricing']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span> {{ __('Pricing') }}
            </a>
            @endif
            <a href="{{ route('settings.edit', ['tab' => 'website']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span> {{ __('Catalog Website') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'roles']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span> {{ __('Roles') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'staff']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> {{ __('Staff') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'audit']) }}" class="nav-item">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span> {{ __('Audit Log') }}
            </a>
        </nav>

        {{-- Main content --}}
        <div class="settings-content">
            @php
                $allTypes = \App\Models\ShopPaymentMethod::TYPES;
                $typeLabels = \App\Models\ShopPaymentMethod::TYPE_LABELS;
                $flatMethods = $methods->flatten(1);
                $activeCount = $flatMethods->where('is_active', true)->count();
            @endphp

            <div class="pm-page-head">
                <div>
                    <p class="pm-eyebrow">POS configuration</p>
                    <h2 class="pm-page-title">Payment Methods</h2>
                    <p class="pm-page-subtitle">Manage UPI, bank, and wallet accounts used while collecting POS and invoice payments. Active methods appear as selectable account options.</p>
                </div>
                <div class="pm-summary" aria-label="Payment method summary">
                    <div class="pm-summary-card">
                        <span class="pm-summary-label">Total</span>
                        <span class="pm-summary-value">{{ $flatMethods->count() }}</span>
                    </div>
                    <div class="pm-summary-card">
                        <span class="pm-summary-label">Active</span>
                        <span class="pm-summary-value">{{ $activeCount }}</span>
                    </div>
                </div>
            </div>

            <div class="pm-list">
                @foreach($allTypes as $type)
                    @php $typeMethods = $methods->get($type, collect()); @endphp
                    <div class="pm-section" x-data="{ showAdd: false, editId: null }">
                        <div class="pm-section-header">
                            <div class="pm-section-heading">
                                <span class="pm-section-title">
                                    <span class="pm-type-mark" aria-hidden="true">
                                        @if($type === 'upi')
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 7 8"/><path d="M7 13h3a5 5 0 0 0 0-10"/></svg>
                                        @elseif($type === 'bank')
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7"/><path d="M4 10h16"/><path d="M5 21h14"/><path d="M7 10v11"/><path d="M17 10v11"/><path d="M12 10v11"/></svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                                        @endif
                                    </span>
                                    {{ $typeLabels[$type] }}
                                </span>
                                <p class="pm-section-meta">{{ $typeMethods->where('is_active', true)->count() }} active of {{ $typeMethods->count() }} saved</p>
                            </div>
                            <button type="button" class="btn-add" @click="showAdd = !showAdd; editId = null">
                                <span x-show="!showAdd">+ Add {{ $typeLabels[$type] }}</span>
                                <span x-show="showAdd">Cancel</span>
                            </button>
                        </div>

                        <div class="pm-section-body">
                        {{-- Existing methods --}}
                        @forelse($typeMethods as $method)
                            <div class="pm-card" x-data="{ editOpen: false }">
                                <div class="pm-card-row">
                                    <span class="pm-card-name">{{ $method->name }}</span>
                                    <span class="pm-card-detail">{{ $method->account_label }}</span>
                                    <div class="pm-method-actions">
                                        {{-- Toggle switch --}}
                                        <form method="POST" action="{{ route('settings.payment-methods.toggle', $method) }}" style="margin:0;">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="pm-toggle {{ $method->is_active ? 'pm-toggle-on' : '' }}" title="{{ $method->is_active ? 'Disable' : 'Enable' }}">
                                                <span class="pm-toggle-thumb"></span>
                                            </button>
                                        </form>
                                        {{-- Edit toggle --}}
                                        <button type="button" class="btn-icon" title="Edit" @click="editOpen = !editOpen">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        {{-- Delete --}}
                                        <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}" style="margin:0;"
                                              onsubmit="return confirm('Delete \'{{ addslashes($method->name) }}\'?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn-icon btn-icon-danger" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                {{-- Inline edit form --}}
                                <div x-show="editOpen" x-cloak class="edit-form">
                                    <form method="POST" action="{{ route('settings.payment-methods.update', $method) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="type" value="{{ $method->type }}">
                                        <div class="pm-form-grid">
                                            <div class="pm-form-group">
                                                <label class="pm-form-label">Name *</label>
                                                <input type="text" name="name" class="pm-form-input" value="{{ old('name', $method->name) }}" required maxlength="100" placeholder="e.g. PhonePe">
                                            </div>
                                            @if($type === 'upi')
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">UPI ID</label>
                                                    <input type="text" name="upi_id" class="pm-form-input" value="{{ old('upi_id', $method->upi_id) }}" maxlength="100" placeholder="name@upi">
                                                </div>
                                            @elseif($type === 'bank')
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Account Holder</label>
                                                    <input type="text" name="account_holder" class="pm-form-input" value="{{ old('account_holder', $method->account_holder) }}" maxlength="100">
                                                </div>
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Bank Name</label>
                                                    <input type="text" name="bank_name" class="pm-form-input" value="{{ old('bank_name', $method->bank_name) }}" maxlength="100" placeholder="e.g. HDFC Bank">
                                                </div>
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Account Number</label>
                                                    <input type="text" name="account_number" class="pm-form-input" value="{{ old('account_number', $method->account_number) }}" maxlength="50">
                                                </div>
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">IFSC Code</label>
                                                    <input type="text" name="ifsc_code" class="pm-form-input" value="{{ old('ifsc_code', $method->ifsc_code) }}" maxlength="20" style="text-transform:uppercase;">
                                                </div>
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Account Type</label>
                                                    <select name="account_type" class="pm-form-select">
                                                        <option value="">— Select —</option>
                                                        <option value="savings" {{ $method->account_type === 'savings' ? 'selected' : '' }}>Savings</option>
                                                        <option value="current" {{ $method->account_type === 'current' ? 'selected' : '' }}>Current</option>
                                                        <option value="overdraft" {{ $method->account_type === 'overdraft' ? 'selected' : '' }}>Overdraft</option>
                                                    </select>
                                                </div>
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Branch</label>
                                                    <input type="text" name="branch" class="pm-form-input" value="{{ old('branch', $method->branch) }}" maxlength="100">
                                                </div>
                                            @elseif($type === 'wallet')
                                                <div class="pm-form-group">
                                                    <label class="pm-form-label">Wallet ID / Account</label>
                                                    <input type="text" name="wallet_id" class="pm-form-input" value="{{ old('wallet_id', $method->wallet_id) }}" maxlength="100">
                                                </div>
                                            @endif
                                        </div>
                                        <div class="pm-form-actions">
                                            <button type="submit" class="btn-save">Save Changes</button>
                                            <button type="button" class="btn-cancel" @click="editOpen = false">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="pm-empty">No {{ strtolower($typeLabels[$type]) }} methods configured yet.</p>
                        @endforelse

                        {{-- Add new form --}}
                        <div x-show="showAdd" x-cloak class="pm-form">
                            <p class="pm-form-title">Add New {{ $typeLabels[$type] }}</p>
                            <form method="POST" action="{{ route('settings.payment-methods.store') }}">
                                @csrf
                                <input type="hidden" name="type" value="{{ $type }}">
                                <div class="pm-form-grid">
                                    <div class="pm-form-group">
                                        <label class="pm-form-label">Name *</label>
                                        <input type="text" name="name" class="pm-form-input" required maxlength="100"
                                               placeholder="{{ $type === 'cash' ? 'e.g. Cash' : ($type === 'upi' ? 'e.g. PhonePe' : ($type === 'bank' ? 'e.g. HDFC Savings' : ($type === 'wallet' ? 'e.g. Paytm Wallet' : 'Name'))) }}">
                                    </div>
                                    @if($type === 'upi')
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">UPI ID</label>
                                            <input type="text" name="upi_id" class="pm-form-input" maxlength="100" placeholder="yourname@upi">
                                        </div>
                                    @elseif($type === 'bank')
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Account Holder</label>
                                            <input type="text" name="account_holder" class="pm-form-input" maxlength="100">
                                        </div>
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Bank Name</label>
                                            <input type="text" name="bank_name" class="pm-form-input" maxlength="100" placeholder="e.g. HDFC Bank">
                                        </div>
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Account Number</label>
                                            <input type="text" name="account_number" class="pm-form-input" maxlength="50">
                                        </div>
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">IFSC Code</label>
                                            <input type="text" name="ifsc_code" class="pm-form-input" maxlength="20" style="text-transform:uppercase;">
                                        </div>
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Account Type</label>
                                            <select name="account_type" class="pm-form-select">
                                                <option value="">— Select —</option>
                                                <option value="savings">Savings</option>
                                                <option value="current">Current</option>
                                                <option value="overdraft">Overdraft</option>
                                            </select>
                                        </div>
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Branch</label>
                                            <input type="text" name="branch" class="pm-form-input" maxlength="100">
                                        </div>
                                    @elseif($type === 'wallet')
                                        <div class="pm-form-group">
                                            <label class="pm-form-label">Wallet ID / Account</label>
                                            <input type="text" name="wallet_id" class="pm-form-input" maxlength="100">
                                        </div>
                                    @endif
                                </div>
                                <div class="pm-form-actions">
                                    <button type="submit" class="btn-save">Add Method</button>
                                    <button type="button" class="btn-cancel" @click="showAdd = false">Cancel</button>
                                </div>
                            </form>
                        </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
</x-app-layout>
