<x-app-layout>
<style>
    .settings-shell {
        padding: 0 24px 24px !important;
    }

    .settings-layout {
        display: grid;
        grid-template-columns: 240px 1fr;
        gap: 18px;
        align-items: start;
        min-height: calc(100vh - 140px);
        background: transparent;
        border: none;

        padding: 0;
        box-shadow: none;
    }

    /* Sidebar */
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
        overflow: auto;
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

    .nav-item:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    .nav-item.active {
        background: #0f766e;
        color: #ffffff;
        box-shadow: 0 10px 18px rgba(15, 118, 110, 0.2);
        border-radius: 9999px;
    }

    .nav-item.active .nav-icon {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
    }

    .nav-icon {
        font-size: 14px;
        width: 24px;
        height: 24px;
        border-radius: 8px;
        background: #f1f5f9;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        flex-shrink: 0;
    }

    /* Content */
    .settings-content {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        padding: 24px 28px;
        overflow-y: auto;
        min-width: 0;
        box-shadow: 0 18px 30px rgba(15, 23, 42, 0.06);
    }

    .settings-header {
        margin-bottom: 20px;
        padding-bottom: 14px;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        position: relative;
    }

    .settings-header::after {
        content: "";
        position: absolute;
        left: 0;
        bottom: -1px;
        width: 64px;
        height: 3px;
        border-radius: 9999px;
        background: #0f766e;
    }

    .settings-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 4px 0;
    }

    .settings-desc {
        font-size: 13px;
        color: #64748b;
        margin: 0;
    }

    /* Form */
    .form-row {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 16px;
    }

    .form-row.cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }

    .form-row.cols-4 {
        grid-template-columns: repeat(4, 1fr);
    }

    .field {
        display: flex;
        flex-direction: column;
    }

    .field.span-2 {
        grid-column: span 2;
    }

    .field.span-3 {
        grid-column: span 3;
    }

    .field-label {
        font-size: 12px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
    }

    .field-input {
        padding: 10px 12px;
        font-size: 13px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        border-radius: 12px;
        background: #f8fafc;
        transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    }

    .field-input:focus {
        outline: none;
        border-color: #0f766e;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
    }

    textarea.field-input {
        resize: vertical;
        min-height: 72px;
    }

    .field-hint {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 4px;
    }

    .logo-upload-wrap {
        position: relative;
        display: grid;
        grid-template-columns: 180px minmax(0, 1fr);
        gap: 16px;
        align-items: start;
        padding: 16px;
        border: 1px dashed rgba(15, 23, 42, 0.22);
        border-radius: 18px;
        background: linear-gradient(145deg, #f8fbff, #f1f7ff);
        transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        cursor: pointer;
    }

    .logo-upload-wrap:hover {
        border-color: rgba(15, 118, 110, 0.45);
        background: linear-gradient(145deg, #f4fbfa, #ecf8f6);
    }

    .logo-upload-wrap:focus-visible {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.18);
    }

    .logo-upload-wrap.is-dragover {
        border-color: #0f766e;
        background: linear-gradient(145deg, #eaf9f6, #e0f3ef);
        box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.14);
    }

    .logo-input-native {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .logo-preview-shell {
        width: 180px;
        height: 180px;
        border-radius: 16px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .logo-preview {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: radial-gradient(circle at center, #ffffff 0%, #f8fafc 100%);
    }

    .logo-meta {
        font-size: 12px;
        color: #64748b;
        line-height: 1.5;
        margin-top: 6px;
    }

    .logo-state {
        margin-top: 8px;
        font-size: 12px;
        color: #334155;
    }

    .logo-upload-title {
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .logo-upload-subtitle {
        margin: 4px 0 0;
        font-size: 12px;
        color: #64748b;
    }

    .logo-upload-actions {
        margin-top: 10px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .logo-browse-btn {
        border: 1px solid #0f766e;
        background: #0f766e;
        color: #ffffff;
        border-radius: 9999px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .logo-format-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.14);
        color: #475569;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    .logo-name {
        margin-top: 4px;
        font-size: 11px;
        color: #64748b;
        word-break: break-all;
    }

    .logo-remove {
        margin-top: 8px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #475569;
    }

    .logo-upload-wrap [data-skip-dropzone-click="true"],
    .logo-upload-wrap .logo-remove,
    .logo-upload-wrap .logo-remove * {
        cursor: default;
    }

    .logo-placeholder-text {
        color: #94a3b8;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .section-divider {
        height: 1px;
        background: rgba(15, 23, 42, 0.08);
        margin: 18px 0;
    }

    .section-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #e0f2f1;
        border-radius: 9999px;
        padding: 4px 10px;
        margin-bottom: 12px;
    }

    /* Actions */
    .form-footer {
        display: flex;
        justify-content: flex-end;
        padding: 12px 16px;
        margin-top: 8px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 14px;
        background: #f8fafc;
    }

    .btn {
        padding: 8px 16px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 10px;
        border: 1px solid transparent;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .btn-primary {
        padding: 9px 18px;
        font-size: 13px;
        font-weight: 600;
        color: #fff;
        background: #0f766e;
        border: none;
        border-radius: 10px;
        cursor: pointer;
    }

    .btn-primary:hover {
        background: #0b5f5d;
    }

    .btn-danger {
        background: #dc2626;
        color: #ffffff;
    }

    .btn-danger:hover {
        background: #b91c1c;
    }

    /* Alert */
    .alert {
        padding: 10px 14px;
        border-radius: 12px;
        margin-bottom: 16px;
        font-size: 13px;
        border: 1px solid transparent;
    }

    .alert-success {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #065f46;
    }

    .alert-error {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .alert-error-list {
        margin: 6px 0 0 16px;
        padding: 0;
    }

    .alert-error-list li {
        margin: 2px 0;
    }

    /* Roles */
    .roles-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
    }

    .role-card {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
    }

    .role-card.locked {
        background: #fffbeb;
        border-color: #fde68a;
    }

    .role-head {
        padding: 12px;
        background: #f8fafc;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .role-card.locked .role-head {
        background: #fef3c7;
    }

    .role-title {
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .role-badge {
        font-size: 10px;
        padding: 2px 6px;
        background: #e2e8f0;
        border-radius: 9999px;
        color: #475569;
    }

    .role-body {
        padding: 12px;
        max-height: 280px;
        overflow-y: auto;
    }

    .perm-group {
        margin-bottom: 12px;
    }

    .perm-group:last-child {
        margin-bottom: 0;
    }

    .perm-group-title {
        font-size: 10px;
        font-weight: 700;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 6px;
    }

    .perm-item {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 3px 0;
        font-size: 12px;
        color: #475569;
        cursor: pointer;
    }

    .perm-item input {
        width: 14px;
        height: 14px;
        accent-color: #0f766e;
    }

    .locked-msg {
        font-size: 12px;
        color: #b45309;
        padding: 16px 12px;
        text-align: center;
    }

    .role-foot {
        padding: 10px 12px;
        background: #f8fafc;
        border-top: 1px solid rgba(15, 23, 42, 0.08);
    }

    .role-card.locked .role-foot {
        display: none;
    }

    .btn-sm {
        width: 100%;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 600;
        color: #fff;
        background: #0f766e;
        border: none;
        border-radius: 10px;
        cursor: pointer;
    }

    .btn-sm:hover {
        background: #0b5f5d;
    }

    /* Staff */
    .staff-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
    }

    .btn-add {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 600;
        color: #fff;
        background: #0f766e;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        text-decoration: none;
    }

    .btn-add:hover {
        background: #0b5f5d;
    }

    .staff-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 12px;
    }

    .staff-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f8fafc;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
    }

    .staff-avatar {
        width: 40px;
        height: 40px;
        border-radius: 9999px;
        background: #0f766e;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
    }

    .staff-info {
        flex: 1;
        min-width: 0;
    }

    .staff-name {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .staff-meta {
        font-size: 11px;
        color: #64748b;
        margin: 2px 0 0 0;
    }

    .staff-role {
        display: inline-block;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 9999px;
        font-weight: 600;
    }

    .staff-role.owner {
        background: #ffedd5;
        color: #c2410c;
    }

    .staff-role.manager {
        background: #e0f2fe;
        color: #0369a1;
    }

    .staff-role.staff {
        background: #e2e8f0;
        color: #475569;
    }

    .staff-actions {
        display: flex;
        gap: 4px;
    }

    .staff-actions a,
    .staff-actions button {
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        border: 1px solid rgba(15, 23, 42, 0.12);

        color: #64748b;
        cursor: pointer;
        font-size: 12px;
        text-decoration: none;
    }

    .staff-actions a:hover {
        background: #f1f5f9;
        color: #0f766e;
    }

    .staff-actions button:hover {
        background: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #94a3b8;
    }

    .empty-icon {
        font-size: 40px;
        margin-bottom: 12px;
    }

    .empty-text {
        font-size: 14px;
        margin-bottom: 16px;
    }

    .logo-preview-hidden {
        display: none;
    }

    .logo-preview-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .logo-upload-meta-col {
        flex: 1;
        min-width: 0;
    }

    .field-input-readonly {
        background: #f1f5f9;
        color: #64748b;
        cursor: not-allowed;
    }

    .settings-desc-gap {
        margin-bottom: 12px;
    }

    .input-uppercase {
        text-transform: uppercase;
    }

    .settings-inline-hint {
        font-size: 11px;
        color: #94a3b8;
        font-weight: 400;
    }

    .settings-signature-box {
        margin-bottom: 8px;
        padding: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        display: inline-block;
    }

    .settings-signature-img {
        max-height: 60px;
        max-width: 200px;
        display: block;
    }

    .settings-signature-note {
        font-size: 12px;
        color: #64748b;
        margin-bottom: 6px;
    }

    .settings-signature-preview-wrap {
        margin-bottom: 8px;
    }

    .settings-signature-preview {
        max-height: 60px;
        max-width: 200px;
        display: none;
        border: 1px solid #e2e8f0;
        padding: 4px;
    }

    .settings-toggle-label {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        margin-top: 6px;
    }

    .settings-toggle-label-no-margin {
        margin-top: 0;
    }

    .settings-toggle-label-gap8 {
        gap: 8px;
    }

    .settings-toggle-label-gap12 {
        gap: 12px;
    }

    .settings-toggle-label-start {
        align-items: flex-start;
    }

    .settings-toggle-input-lg {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    .settings-toggle-input-md {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .settings-toggle-text {
        font-size: 13px;
        color: #374151;
    }

    .settings-warning-note {
        font-size: 12px;
        color: #f59e0b;
        margin-top: 8px;
    }

    .settings-bis-logo {
        height: 44px;
        width: 44px;
        object-fit: contain;
        border: 1px solid #e2e8f0;
        padding: 3px;
    }

    .settings-bis-title {
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 4px;
    }

    .settings-bis-caption {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
    }

    .settings-color-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .settings-color-picker {
        width: 48px;
        height: 36px;
        padding: 2px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
    }

    .settings-color-help {
        font-size: 12px;
        color: #64748b;
    }

    .settings-form-row-wrap {
        flex-wrap: wrap;
        gap: 12px;
    }

    .settings-field-flex {
        min-width: 260px;
        flex: 1;
    }

    .settings-checkbox-top {
        padding-top: 2px;
    }

    .settings-checkbox-title {
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
    }

    .settings-checkbox-hint {
        font-size: 11px;
        color: #64748b;
        margin-top: 2px;
    }

    .settings-section-top {
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }

    .settings-section-title {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }

    .settings-section-subtitle {
        font-size: 13px;
        color: #64748b;
        margin-bottom: 16px;
    }

    .settings-staff-limit-card {
        margin-bottom: 14px;
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
    }

    .settings-staff-limit-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }

    .settings-staff-limit-title {
        font-size: 13px;
        font-weight: 600;
        color: #374151;
    }

    .settings-staff-limit-usage {
        font-size: 13px;
        color: #6b7280;
    }

    .settings-status-unlimited {
        color: #22c55e;
    }

    .settings-status-limit {
        color: #ef4444;
        font-weight: 600;
    }

    .settings-progress-track {
        height: 6px;
        background: #e5e7eb;
        border-radius: 9999px;
        overflow: hidden;
    }

    .settings-progress-fill {
        height: 100%;
        border-radius: 9999px;
        transition: width .3s;
    }

    .settings-staff-count {
        font-size: 13px;
        color: #666;
    }

    .settings-btn-disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    /* Responsive */
    @media (max-width: 900px) {
        .settings-layout {
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .settings-nav {
            position: static;
            flex-direction: row;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding: 8px;
            scrollbar-width: none;
        }

        .nav-item {
            padding: 8px 12px;
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .form-row {
            grid-template-columns: 1fr 1fr;
        }

        .logo-upload-wrap {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .logo-preview-shell {
            width: 140px;
            height: 140px;
        }

        .roles-container {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 600px) {
        .settings-shell {
            padding: 0 12px 16px !important;
        }

        .settings-content {
            padding: 18px;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .field.span-2,
        .field.span-3 {
            grid-column: span 1;
        }

        .logo-upload-wrap {
            padding: 12px;
            border-radius: 14px;
        }

        .logo-preview-shell {
            width: 124px;
            height: 124px;
        }

        .logo-upload-title {
            font-size: 13px;
        }

        .logo-upload-subtitle,
        .logo-meta {
            font-size: 11px;
        }
    }
</style>


<x-page-header class="settings-page-header">
    <div>
        <h1 class="page-title">{{ __('Settings') }}</h1>
    </div>
</x-page-header>

<div class="content-inner settings-shell">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-error">
            <strong>{{ __('Please fix the following before saving:') }}</strong>
            <ul class="alert-error-list">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="settings-layout">
        <!-- Sidebar Navigation -->
        <nav class="settings-nav">
            <a href="{{ route('settings.edit', ['tab' => 'general']) }}" class="nav-item {{ $activeTab === 'general' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> {{ __('General') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'shop']) }}" class="nav-item {{ $activeTab === 'shop' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> {{ __('Shop Info') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'rules']) }}" class="nav-item {{ $activeTab === 'rules' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></span> {{ auth()->user()->shop?->isRetailer() ? __('Sale Rules') : __('Gold & POS') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'billing']) }}" class="nav-item {{ $activeTab === 'billing' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span> {{ __('Invoice') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'preferences']) }}" class="nav-item {{ $activeTab === 'preferences' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span> {{ __('Preferences') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'website']) }}" class="nav-item {{ $activeTab === 'website' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span> {{ __('Catalog Website') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'roles']) }}" class="nav-item {{ $activeTab === 'roles' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span> {{ __('Roles') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'staff']) }}" class="nav-item {{ $activeTab === 'staff' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> {{ __('Staff') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'audit']) }}" class="nav-item {{ $activeTab === 'audit' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span> {{ __('Audit Log') }}
            </a>
        </nav>

        <!-- Content Area -->
        <turbo-frame id="settings-content">
        <div class="settings-content">
            @if($activeTab === 'general')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('General Settings') }}</h2>
                    <p class="settings-desc">{{ __('Account, session, and basic app actions') }}</p>
                </div>

                <div class="section-label">{{ __('Account') }}</div>
                <div class="bg-gray-50 border border-gray-200 p-4 text-sm">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-600">{{ __('Logged in as') }}</span>
                            <span class="font-semibold text-gray-900">{{ auth()->user()->mobile_number }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-600">{{ __('Role') }}</span>
                            <span class="font-semibold text-gray-900">{{ auth()->user()->isOwner() ? __('Owner') : __('Cashier') }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-600">{{ __('Shop') }}</span>
                            <span class="font-semibold text-gray-900">{{ auth()->user()->shop?->name ?? '—' }}</span>
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>
                <div class="section-label">{{ __('Session') }}</div>

                <div class="bg-red-50 border border-red-200 p-4 text-sm">
                    <div class="flex flex-col gap-3">
                        <div>
                            <p class="font-semibold text-red-900">{{ __('Logout') }}</p>
                            <p class="text-red-700 text-xs mt-1">{{ __('You will be signed out of JewelFlow on this device.') }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>{{ __('Logout') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @if($activeTab === 'shop')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Shop Information') }}</h2>
                    <p class="settings-desc">{{ __('Business identity and tax configuration') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.shop') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')
                    
                    <div class="section-label">{{ __('Business Details') }}</div>
                    <div class="form-row">
                        <div class="field span-2">
                            <label class="field-label">{{ __('Shop Name') }}</label>
                            <input type="text" name="name" value="{{ old('name', $shop->name) }}" class="field-input" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Phone') }}</label>
                            <input type="text" name="phone" value="{{ old('phone', $shop->phone) }}" class="field-input" maxlength="10" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('WhatsApp Number') }}</label>
                            <input type="text" name="shop_whatsapp" value="{{ old('shop_whatsapp', $shop->shop_whatsapp) }}" class="field-input" maxlength="10" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Shop Email') }}</label>
                            <input type="email" name="shop_email" value="{{ old('shop_email', $shop->shop_email) }}" class="field-input" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Established Year') }}</label>
                            <input type="number" name="established_year" value="{{ old('established_year', $shop->established_year) }}" class="field-input" min="1900" max="{{ now()->year }}" placeholder="{{ __('Optional') }}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field span-2">
                            <label class="field-label">{{ __('Shop Registration Number') }}</label>
                            <input type="text" name="shop_registration_number" value="{{ old('shop_registration_number', $shop->shop_registration_number) }}" class="field-input" placeholder="{{ __('Optional — e.g. MSME/Udyam/Trade License no.') }}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field span-3">
                            <label class="field-label">{{ __('Shop Logo') }}</label>
                            <div class="logo-upload-wrap" id="shop-logo-dropzone" tabindex="0" role="button" aria-label="Upload shop logo">
                                @php
                                    $logoUrl = $shop->logo_path ? asset('storage/' . ltrim($shop->logo_path, '/')) : '';
                                @endphp
                                <input id="shop-logo-input" type="file" name="logo" accept="image/png,image/jpeg,image/jpg,image/webp" class="logo-input-native">
                                <div class="logo-preview-shell" aria-hidden="true">
                                    <img
                                        id="shop-logo-preview"
                                        src="{{ $logoUrl }}"
                                        alt="{{ __('Shop Logo') }}"
                                        onerror="this.style.display='none'; var p=document.getElementById('shop-logo-placeholder'); if(p){p.style.display='flex';} var s=document.getElementById('shop-logo-state-text'); if(s){s.textContent='{{ __('Uploaded (file unavailable)') }}';}"
                                        class="logo-preview {{ $shop->logo_path ? '' : 'logo-preview-hidden' }}"
                                    >
                                    <div
                                        id="shop-logo-placeholder"
                                        class="logo-preview logo-preview-placeholder {{ $shop->logo_path ? 'logo-preview-hidden' : '' }}"
                                    ><span class="logo-placeholder-text">{{ __('No Logo') }}</span></div>
                                </div>
                                <div class="logo-upload-meta-col" data-skip-dropzone-click="true">
                                    <p class="logo-upload-title">{{ __('Drag & drop your logo here') }}</p>
                                    <p class="logo-upload-subtitle">{{ __('or click this area to browse files') }}</p>
                                    <div class="logo-upload-actions">
                                        <button type="button" id="shop-logo-browse" class="logo-browse-btn" data-skip-dropzone-click="true">{{ __('Choose Logo') }}</button>
                                        <span class="logo-format-chip">PNG / JPG / WEBP</span>
                                    </div>
                                    <div class="logo-meta">{{ __('Upload PNG/JPG/WEBP. Max 2 MB. Recommended square logo.') }}</div>
                                    <div class="logo-state">
                                        <strong>{{ __('Current Logo:') }}</strong>
                                        <span id="shop-logo-state-text">{{ $shop->logo_path ? __('Uploaded') : __('Not uploaded') }}</span>
                                    </div>
                                    <div id="shop-logo-file-name" class="logo-name">{{ $shop->logo_path ? basename($shop->logo_path) : '' }}</div>
                                    @if($shop->logo_path)
                                        <label class="logo-remove">
                                            <input id="shop-logo-remove" type="checkbox" name="remove_logo" value="1">
                                            <span>{{ __('Remove current logo') }}</span>
                                        </label>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-label">{{ __('Shop Address') }}</div>
                    <div class="form-row">
                        <div class="field span-3">
                            <label class="field-label">{{ __('Address Line 1') }}</label>
                            <input type="text" name="address_line1" value="{{ old('address_line1', $shop->address_line1) }}" class="field-input" placeholder="{{ __('Building, Street') }}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="field span-3">
                            <label class="field-label">{{ __('Address Line 2 (Optional)') }}</label>
                            <input type="text" name="address_line2" value="{{ old('address_line2', $shop->address_line2) }}" class="field-input" placeholder="{{ __('Landmark, Area') }}">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('City') }}</label>
                            <input type="text" name="city" value="{{ old('city', $shop->city) }}" class="field-input" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('State') }}</label>
                            <select name="state" id="gst-state-select" class="field-input" required onchange="updateStateCode(this)">
                                <option value="">— Select State —</option>
                                @php
                                $gstStates = [
                                    '01'=>'Jammu & Kashmir','02'=>'Himachal Pradesh','03'=>'Punjab',
                                    '04'=>'Chandigarh','05'=>'Uttarakhand','06'=>'Haryana',
                                    '07'=>'Delhi','08'=>'Rajasthan','09'=>'Uttar Pradesh',
                                    '10'=>'Bihar','11'=>'Sikkim','12'=>'Arunachal Pradesh',
                                    '13'=>'Nagaland','14'=>'Manipur','15'=>'Mizoram',
                                    '16'=>'Tripura','17'=>'Meghalaya','18'=>'Assam',
                                    '19'=>'West Bengal','20'=>'Jharkhand','21'=>'Odisha',
                                    '22'=>'Chhattisgarh','23'=>'Madhya Pradesh','24'=>'Gujarat',
                                    '25'=>'Daman & Diu','26'=>'Dadra & Nagar Haveli',
                                    '27'=>'Maharashtra','28'=>'Andhra Pradesh (Old)',
                                    '29'=>'Karnataka','30'=>'Goa','31'=>'Lakshadweep',
                                    '32'=>'Kerala','33'=>'Tamil Nadu','34'=>'Puducherry',
                                    '35'=>'Andaman & Nicobar Islands','36'=>'Telangana',
                                    '37'=>'Andhra Pradesh',
                                ];
                                $savedState = old('state', $shop->state);
                                @endphp
                                @foreach($gstStates as $code => $stateName)
                                    <option value="{{ $stateName }}" data-code="{{ $code }}"
                                        {{ $savedState === $stateName ? 'selected' : '' }}>
                                        {{ $code }} — {{ $stateName }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('GST State Code') }}</label>
                            <input type="text" id="gst-state-code-display" name="state_code"
                                value="{{ old('state_code', $shop->state_code) }}"
                                class="field-input field-input-readonly" readonly
                                placeholder="Auto-filled">
                            <span class="field-hint">Auto-filled from state selection</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Pincode') }}</label>
                            <input type="text" name="pincode" value="{{ old('pincode', $shop->pincode) }}" class="field-input" maxlength="6" required>
                        </div>
                    </div>
                    <script>
                    function updateStateCode(sel) {
                        const opt = sel.options[sel.selectedIndex];
                        document.getElementById('gst-state-code-display').value = opt.dataset.code || '';
                    }
                    // Init on page load
                    document.addEventListener('DOMContentLoaded', function() {
                        const sel = document.getElementById('gst-state-select');
                        if (sel) updateStateCode(sel);
                    });
                    </script>
                    
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('GST Number') }}</label>
                            <input type="text" name="gst_number" value="{{ old('gst_number', $shop->gst_number) }}" class="field-input" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('GST Rate (%)') }}</label>
                            <input type="number" name="gst_rate" value="{{ old('gst_rate', $shop->gst_rate) }}" class="field-input" step="0.01" min="0" max="100" required>
                        </div>
                        @if(auth()->user()->shop?->isManufacturer())
                        <div class="field">
                            <label class="field-label">{{ __('Wastage Recovery (%)') }}</label>
                            <input type="number" name="wastage_recovery_percent" value="{{ old('wastage_recovery_percent', $shop->wastage_recovery_percent) }}" class="field-input" step="0.01" min="0" required>
                        </div>
                        @endif
                    </div>
                    
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Owner Details') }}</div>
                    
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('First Name') }}</label>
                            <input type="text" name="owner_first_name" value="{{ old('owner_first_name', $shop->owner_first_name) }}" class="field-input" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Last Name') }}</label>
                            <input type="text" name="owner_last_name" value="{{ old('owner_last_name', $shop->owner_last_name) }}" class="field-input" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Mobile') }}</label>
                            <input type="text" name="owner_mobile" value="{{ old('owner_mobile', $shop->owner_mobile) }}" class="field-input" maxlength="10" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Email') }}</label>
                            <input type="email" name="owner_email" value="{{ old('owner_email', $shop->owner_email) }}" class="field-input" placeholder="{{ __('Optional') }}">
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                </form>
            @endif

            @if($activeTab === 'rules')
                <div class="settings-header">
                    <h2 class="settings-title">{{ auth()->user()->shop?->isRetailer() ? __('Sale & POS Rules') : __('Gold & POS Rules') }}</h2>
                    <p class="settings-desc">{{ __('Calculation settings for pricing, exchange, and buyback') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.rules') }}">
                    @csrf
                    @method('PATCH')
                    
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Default Purity') }}</label>
                            <select name="default_purity" class="field-input" required>
                                <option value="24K" {{ $rules->default_purity === '24K' ? 'selected' : '' }}>24K (99.9%)</option>
                                <option value="22K" {{ $rules->default_purity === '22K' ? 'selected' : '' }}>22K (91.6%)</option>
                                <option value="18K" {{ $rules->default_purity === '18K' ? 'selected' : '' }}>18K (75.0%)</option>
                                <option value="14K" {{ $rules->default_purity === '14K' ? 'selected' : '' }}>14K (58.3%)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Rounding Precision') }}</label>
                            <select name="rounding_precision" class="field-input" required>
                                <option value="0" {{ $rules->rounding_precision == 0 ? 'selected' : '' }}>{{ __('0 decimals') }}</option>
                                <option value="1" {{ $rules->rounding_precision == 1 ? 'selected' : '' }}>{{ __('1 decimal') }}</option>
                                <option value="2" {{ $rules->rounding_precision == 2 ? 'selected' : '' }}>{{ __('2 decimals') }}</option>
                                <option value="3" {{ $rules->rounding_precision == 3 ? 'selected' : '' }}>{{ __('3 decimals') }}</option>
                            </select>
                        </div>
                    </div>
                    
                    @if(auth()->user()->shop?->isManufacturer())
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Default Making Charge Type') }}</label>
                            <select name="default_making_type" class="field-input" required>
                                <option value="per_gram" {{ $rules->default_making_type === 'per_gram' ? 'selected' : '' }}>{{ __('Per Gram (₹/g)') }}</option>
                                <option value="percent" {{ $rules->default_making_type === 'percent' ? 'selected' : '' }}>{{ __('Percentage (%)') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Default Making Value') }}</label>
                            <input type="number" name="default_making_value" value="{{ old('default_making_value', $rules->default_making_value) }}" class="field-input" step="0.01" min="0" required>
                        </div>
                    </div>

                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Test Loss (%)') }}</label>
                            <input type="number" name="test_loss_percent" value="{{ old('test_loss_percent', $rules->test_loss_percent) }}" class="field-input" step="0.01" min="0" max="100" required>
                            <span class="field-hint">{{ __('Gold lost during purity testing') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Buyback Rate (%)') }}</label>
                            <input type="number" name="buyback_percent" value="{{ old('buyback_percent', $rules->buyback_percent) }}" class="field-input" step="0.01" min="0" max="100" required>
                            <span class="field-hint">{{ __('% of gold rate for buybacks') }}</span>
                        </div>
                    </div>
                    @else
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Buyback Rate (%)') }}</label>
                            <input type="number" name="buyback_percent" value="{{ old('buyback_percent', $rules->buyback_percent) }}" class="field-input" step="0.01" min="0" max="100" required>
                            <span class="field-hint">{{ __('% of gold rate for buybacks') }}</span>
                        </div>
                    </div>
                    @endif

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Per-Category GST Rates') }}</div>
                    <p class="settings-desc settings-desc-gap">{{ __('Override the default GST rate for specific product categories on invoices. Leave blank to use the shop-level GST rate.') }}</p>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Gold GST Rate (%)') }}</label>
                            <input type="number" name="gst_rate_gold" value="{{ old('gst_rate_gold', $rules->gst_rate_gold) }}" class="field-input" step="0.01" min="0" max="100" placeholder="{{ __('e.g. 3') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Silver GST Rate (%)') }}</label>
                            <input type="number" name="gst_rate_silver" value="{{ old('gst_rate_silver', $rules->gst_rate_silver) }}" class="field-input" step="0.01" min="0" max="100" placeholder="{{ __('e.g. 3') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Diamond / Stone GST Rate (%)') }}</label>
                            <input type="number" name="gst_rate_diamond" value="{{ old('gst_rate_diamond', $rules->gst_rate_diamond) }}" class="field-input" step="0.01" min="0" max="100" placeholder="{{ __('e.g. 1.5') }}">
                        </div>
                    </div>

                    @if(auth()->user()->shop?->isManufacturer())
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Wastage Calculation') }}</div>
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Wastage Rounding Precision') }}</label>
                            <select name="wastage_rounding" class="field-input">
                                <option value="0.001" {{ ($rules->wastage_rounding ?? '0.001') === '0.001' ? 'selected' : '' }}>{{ __('3 decimals (0.001 g)') }}</option>
                                <option value="0.01"  {{ ($rules->wastage_rounding ?? '') === '0.01'  ? 'selected' : '' }}>{{ __('2 decimals (0.01 g)') }}</option>
                                <option value="0.1"   {{ ($rules->wastage_rounding ?? '') === '0.1'   ? 'selected' : '' }}>{{ __('1 decimal (0.1 g)') }}</option>
                                <option value="1"     {{ ($rules->wastage_rounding ?? '') === '1'     ? 'selected' : '' }}>{{ __('Whole grams (1 g)') }}</option>
                            </select>
                        </div>
                    </div>
                    @endif

                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                </form>
            @endif

            @if($activeTab === 'billing')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Invoice Settings') }}</h2>
                    <p class="settings-desc">{{ __('Customize invoice appearance and payment details') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.billing') }}" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Invoice Prefix') }}</label>
                            <input type="text" name="invoice_prefix" value="{{ old('invoice_prefix', $billing->invoice_prefix) }}" class="field-input" maxlength="20" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Starting Number') }}</label>
                            <input type="number" name="invoice_start_number" value="{{ old('invoice_start_number', $billing->invoice_start_number) }}" class="field-input" min="1" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('UPI ID') }}</label>
                            <input type="text" name="upi_id" value="{{ old('upi_id', $billing->upi_id) }}" class="field-input" placeholder="{{ __('name@upi') }}">
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Bank Details') }}</div>
                    <p class="settings-desc settings-desc-gap">{{ __('These details appear on invoices under Payment Details.') }}</p>

                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Account Holder Name') }}</label>
                            <input type="text" name="bank_account_holder" value="{{ old('bank_account_holder', $billing->bank_account_holder) }}" class="field-input" placeholder="{{ __('e.g. Goldlux Jewellers') }}" maxlength="100">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Bank Name') }}</label>
                            <input type="text" name="bank_name" value="{{ old('bank_name', $billing->bank_name) }}" class="field-input" placeholder="{{ __('e.g. State Bank of India') }}" maxlength="100">
                        </div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Account Number') }}</label>
                            <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $billing->bank_account_number) }}" class="field-input" placeholder="{{ __('e.g. 1234567890') }}" maxlength="30">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('IFSC Code') }}</label>
                            <input type="text" name="bank_ifsc" value="{{ old('bank_ifsc', $billing->bank_ifsc) }}" class="field-input input-uppercase" placeholder="{{ __('e.g. SBIN0001234') }}" maxlength="20">
                        </div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Account Type') }}</label>
                            <select name="bank_account_type" class="field-input">
                                <option value="">{{ __('— Select —') }}</option>
                                <option value="current" {{ old('bank_account_type', $billing->bank_account_type) === 'current' ? 'selected' : '' }}>{{ __('Current') }}</option>
                                <option value="savings" {{ old('bank_account_type', $billing->bank_account_type) === 'savings' ? 'selected' : '' }}>{{ __('Savings') }}</option>
                                <option value="overdraft" {{ old('bank_account_type', $billing->bank_account_type) === 'overdraft' ? 'selected' : '' }}>{{ __('Overdraft') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Branch') }}</label>
                            <input type="text" name="bank_branch" value="{{ old('bank_branch', $billing->bank_branch) }}" class="field-input" placeholder="{{ __('e.g. MG Road, Mumbai') }}" maxlength="100">
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Terms & Conditions') }}
                                <span class="settings-inline-hint"> (max 6 lines)</span>
                            </label>
                            <textarea name="terms_and_conditions" class="field-input" rows="6" placeholder="{{ __('One point per line. Max 6 lines.') }}">{{ old('terms_and_conditions', $billing->terms_and_conditions) }}</textarea>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Digital Signature') }}</div>

                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Upload Signature Image') }}</label>
                            @if($billing->digital_signature_path)
                                <div class="settings-signature-box">
                                    <img src="{{ asset('storage/' . $billing->digital_signature_path) }}"
                                         alt="Current Signature" id="sig-preview"
                                         class="settings-signature-img">
                                </div>
                                <div class="settings-signature-note">Current signature uploaded. Upload a new one to replace it.</div>
                            @else
                                <div class="settings-signature-preview-wrap">
                                    <img id="sig-preview" src="" alt="Digital signature preview" class="settings-signature-preview">
                                </div>
                            @endif
                            <input type="file" name="digital_signature" id="sig-upload" accept="image/png,image/jpeg,image/jpg"
                                class="field-input" onchange="previewSig(this)">
                            <span class="field-hint">PNG/JPG with transparent background preferred. Max 500 KB.</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Show on Invoice') }}</label>
                            <label class="settings-toggle-label">
                                <input type="hidden" name="show_digital_signature" value="0">
                                <input type="checkbox" name="show_digital_signature" value="1"
                                    {{ old('show_digital_signature', $billing->show_digital_signature) ? 'checked' : '' }}
                                    class="settings-toggle-input-lg">
                                <span class="settings-toggle-text">Include digital signature on printed invoice</span>

                            </label>
                            @if(!$billing->digital_signature_path)
                                <p class="settings-warning-note">⚠ Upload a signature image first for it to appear.</p>
                            @endif
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('BIS Hallmark Logo') }}</div>

                    <div class="form-row">
                        <div class="field">
                            <label class="settings-toggle-label settings-toggle-label-gap12 settings-toggle-label-no-margin">
                                <img src="{{ asset('images/bis_hallmark_logo.svg') }}" alt="BIS Logo"
                                     class="settings-bis-logo">
                                <div>
                                    <div class="settings-bis-title">Show BIS Hallmark Logo on Invoice</div>
                                    <label class="settings-toggle-label settings-toggle-label-gap8 settings-toggle-label-no-margin">
                                        <input type="hidden" name="show_bis_logo" value="0">
                                        <input type="checkbox" name="show_bis_logo" value="1"
                                            {{ old('show_bis_logo', $billing->show_bis_logo) ? 'checked' : '' }}
                                            class="settings-toggle-input-md">
                                        <span class="settings-toggle-text">Include BIS logo in invoice header</span>
                                    </label>
                                    <p class="settings-bis-caption">Use only if your shop is BIS Hallmark certified.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- ── Branding ──────────────────────────────────────────────────── --}}
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Invoice Appearance') }}</div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Theme Color') }}</label>
                            <div class="settings-color-row">
                                <input type="color" name="theme_color"
                                       value="{{ old('theme_color', $billing->theme_color ?? '#111111') }}"
                                       class="settings-color-picker">
                                <span class="settings-color-help">Border &amp; header accent color on invoice</span>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Font Size') }}</label>
                            <select name="font_size" class="field-input">
                                @foreach(['compact' => 'Compact (9.5px)', 'normal' => 'Normal (11px)', 'large' => 'Large (13px)'] as $val => $label)
                                    <option value="{{ $val }}" {{ old('font_size', $billing->font_size ?? 'normal') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Paper Size') }}</label>
                            <select name="paper_size" class="field-input">
                                @foreach(['a4' => 'A4 (Standard)', 'a5' => 'A5 (Half page)', 'thermal' => 'Thermal (80mm)'] as $val => $label)
                                    <option value="{{ $val }}" {{ old('paper_size', $billing->paper_size ?? 'a4') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Shop Subtitle') }}</label>
                            <input type="text" name="shop_subtitle"
                                   value="{{ old('shop_subtitle', $billing->shop_subtitle) }}"
                                   class="field-input" maxlength="100"
                                   placeholder="GOLD • SILVER • DIAMOND">
                            <span class="field-hint">Shown below shop name. Leave blank to use default.</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Custom Tagline') }}</label>
                            <input type="text" name="custom_tagline"
                                   value="{{ old('custom_tagline', $billing->custom_tagline) }}"
                                   class="field-input" maxlength="150"
                                   placeholder="e.g. Certified BIS Hallmark Dealer">
                            <span class="field-hint">One line shown below the subtitle.</span>
                        </div>
                    </div>

                    {{-- ── Invoice Number ───────────────────────────────────────────── --}}
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Invoice Number Format') }}</div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Invoice Suffix') }}</label>
                            <input type="text" name="invoice_suffix"
                                   value="{{ old('invoice_suffix', $billing->invoice_suffix) }}"
                                   class="field-input" maxlength="20"
                                   placeholder="e.g. /2025-26">
                            <span class="field-hint">Appended after the number, e.g. INV-0001001/2025-26</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Invoice Copy Label') }}</label>
                            <select name="invoice_copy_label" class="field-input">
                                @foreach(['Original', 'Duplicate', 'Triplicate'] as $lbl)
                                    <option value="{{ $lbl }}" {{ old('invoice_copy_label', $billing->invoice_copy_label ?? 'Original') === $lbl ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Print Copies') }}</label>
                            <select name="copy_count" class="field-input">
                                <option value="1" {{ old('copy_count', $billing->copy_count ?? 1) == 1 ? 'selected' : '' }}>1 copy (Customer)</option>
                                <option value="2" {{ old('copy_count', $billing->copy_count ?? 1) == 2 ? 'selected' : '' }}>2 copies (Customer + Shop)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Auto-reset on New Financial Year') }}</label>
                            <label class="settings-toggle-label">
                                <input type="hidden" name="year_reset" value="0">
                                <input type="checkbox" name="year_reset" value="1"
                                    {{ old('year_reset', $billing->year_reset) ? 'checked' : '' }}
                                    class="settings-toggle-input-lg">
                                <span class="settings-toggle-text">Reset invoice counter every April 1st</span>
                            </label>
                            <span class="field-hint">Counter resets to the Starting Number at the beginning of each fiscal year.</span>
                        </div>
                    </div>

                    {{-- ── Column Visibility ────────────────────────────────────────── --}}
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Invoice Column Visibility') }}</div>

                    @php
                        $colToggles = [
                            'show_huid'             => ['label' => 'Show HUID Column',               'hint' => 'Hallmark Unique ID column in items table'],
                            'show_stone_columns'    => ['label' => 'Show Stone Weight & Value',      'hint' => 'Stone Wt. and Stone Val. columns'],
                            'show_purity'           => ['label' => 'Show Purity Column',             'hint' => 'Karat purity column in items table'],
                            'show_gstin'            => ['label' => 'Show GSTIN on Invoice',          'hint' => 'Displays shop GST number at the top'],
                            'show_customer_address' => ['label' => 'Show Customer Address',          'hint' => 'Address row in bill-to section'],
                            'show_customer_id_pan'  => ['label' => 'Show Customer ID / PAN',         'hint' => 'ID number and PAN rows in bill-to section'],
                        ];
                    @endphp

                    <div class="form-row settings-form-row-wrap">
                        @foreach($colToggles as $field => $meta)
                        <div class="field settings-field-flex">
                            <label class="settings-toggle-label settings-toggle-label-start settings-toggle-label-no-margin">
                                <div class="settings-checkbox-top">
                                    <input type="hidden" name="{{ $field }}" value="0">
                                    <input type="checkbox" name="{{ $field }}" value="1"
                                        {{ old($field, $billing->{$field} ?? true) ? 'checked' : '' }}
                                        class="settings-toggle-input-md">
                                </div>
                                <div>
                                    <div class="settings-checkbox-title">{{ $meta['label'] }}</div>
                                    <div class="settings-checkbox-hint">{{ $meta['hint'] }}</div>
                                </div>
                            </label>
                        </div>
                        @endforeach
                    </div>

                    {{-- ── Tax Settings ─────────────────────────────────────────────── --}}
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Tax Settings') }}</div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('GST Display Mode') }}</label>
                            <label class="settings-toggle-label">
                                <input type="hidden" name="igst_mode" value="0">
                                <input type="checkbox" name="igst_mode" value="1"
                                    {{ old('igst_mode', $billing->igst_mode) ? 'checked' : '' }}
                                    class="settings-toggle-input-lg">
                                <span class="settings-toggle-text">Use IGST instead of CGST + SGST</span>
                            </label>
                            <span class="field-hint">Enable for inter-state sales.</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('HSN Code — Gold') }}</label>
                            <input type="text" name="hsn_gold"
                                   value="{{ old('hsn_gold', $billing->hsn_gold ?? '7113') }}"
                                   class="field-input" maxlength="20" placeholder="7113">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('HSN Code — Silver') }}</label>
                            <input type="text" name="hsn_silver"
                                   value="{{ old('hsn_silver', $billing->hsn_silver ?? '7113') }}"
                                   class="field-input" maxlength="20" placeholder="7113">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('HSN Code — Diamond / Gemstone') }}</label>
                            <input type="text" name="hsn_diamond"
                                   value="{{ old('hsn_diamond', $billing->hsn_diamond ?? '7114') }}"
                                   class="field-input" maxlength="20" placeholder="7114">
                        </div>
                    </div>

                    {{-- ── Signature & Footer ───────────────────────────────────────── --}}
                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Signature & Footer') }}</div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Second Signature Label') }}</label>
                            <input type="text" name="second_signature_label"
                                   value="{{ old('second_signature_label', $billing->second_signature_label) }}"
                                   class="field-input" maxlength="100"
                                   placeholder="e.g. Prepared by">
                            <span class="field-hint">Adds a second signature block on the left. Leave blank to show only one.</span>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                </form>
                <script>
                function previewSig(input) {
                    const img = document.getElementById('sig-preview');
                    if (input.files && input.files[0]) {
                        const reader = new FileReader();
                        reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; };
                        reader.readAsDataURL(input.files[0]);
                    }
                }
                </script>
            @endif

            @if($activeTab === 'preferences')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Preferences') }}</h2>
                    <p class="settings-desc">{{ __('Display and formatting options') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.preferences') }}">
                    @csrf
                    @method('PATCH')
                    
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Weight Unit') }}</label>
                            <select name="weight_unit" class="field-input" required>
                                <option value="grams" {{ $preferences->weight_unit === 'grams' ? 'selected' : '' }}>{{ __('Grams') }}</option>
                                <option value="tola" {{ $preferences->weight_unit === 'tola' ? 'selected' : '' }}>{{ __('Tola') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Date Format') }}</label>
                            <select name="date_format" class="field-input" required>
                                <option value="d/m/Y" {{ $preferences->date_format === 'd/m/Y' ? 'selected' : '' }}>DD/MM/YYYY</option>
                                <option value="m/d/Y" {{ $preferences->date_format === 'm/d/Y' ? 'selected' : '' }}>MM/DD/YYYY</option>
                                <option value="Y-m-d" {{ $preferences->date_format === 'Y-m-d' ? 'selected' : '' }}>YYYY-MM-DD</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Currency Symbol') }}</label>
                            <input type="text" name="currency_symbol" value="{{ old('currency_symbol', $preferences->currency_symbol) }}" class="field-input" maxlength="5" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Language') }}</label>
                            <select name="language" class="field-input" required>
                                @foreach(config('app.supported_locales', ['en' => 'English']) as $localeCode => $localeLabel)
                                    <option value="{{ $localeCode }}" {{ old('language', $preferences->language ?? config('app.locale', 'en')) === $localeCode ? 'selected' : '' }}>
                                        {{ __($localeLabel) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Low Stock Alert') }}</label>
                            <input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $preferences->low_stock_threshold) }}" class="field-input" min="0" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Round-off Nearest (₹)') }}</label>
                            <select name="round_off_nearest" class="field-input" required>
                                <option value="1" {{ ($preferences->round_off_nearest ?? 1) == 1 ? 'selected' : '' }}>₹ 1</option>
                                <option value="10" {{ ($preferences->round_off_nearest ?? 1) == 10 ? 'selected' : '' }}>₹ 10</option>
                                <option value="100" {{ ($preferences->round_off_nearest ?? 1) == 100 ? 'selected' : '' }}>₹ 100</option>
                            </select>
                        </div>
                    </div>

                    <div class="settings-section-top">
                        <h3 class="settings-section-title">{{ __('Loyalty Points') }}</h3>
                        <p class="settings-section-subtitle">{{ __('Configure how customers earn and lose points') }}</p>
                    </div>

                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Points per ₹100 spent') }}</label>
                            <input type="number" name="loyalty_points_per_hundred" value="{{ old('loyalty_points_per_hundred', $preferences->loyalty_points_per_hundred ?? 1) }}" class="field-input" min="0" max="100" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Point value (₹)') }}</label>
                            <input type="number" name="loyalty_point_value" value="{{ old('loyalty_point_value', $preferences->loyalty_point_value ?? 0.25) }}" class="field-input" min="0" max="100" step="0.01" required>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Expiry (months)') }}</label>
                            <select name="loyalty_expiry_months" class="field-input" required>
                                <option value="0" {{ ($preferences->loyalty_expiry_months ?? 12) == 0 ? 'selected' : '' }}>{{ __('Never') }}</option>
                                <option value="6" {{ ($preferences->loyalty_expiry_months ?? 12) == 6 ? 'selected' : '' }}>{{ __('6 months') }}</option>
                                <option value="12" {{ ($preferences->loyalty_expiry_months ?? 12) == 12 ? 'selected' : '' }}>{{ __('12 months') }}</option>
                                <option value="18" {{ ($preferences->loyalty_expiry_months ?? 12) == 18 ? 'selected' : '' }}>{{ __('18 months') }}</option>
                                <option value="24" {{ ($preferences->loyalty_expiry_months ?? 12) == 24 ? 'selected' : '' }}>{{ __('24 months') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Loyalty Welcome Bonus (pts)') }}</label>
                            <input type="number" name="loyalty_welcome_bonus" value="{{ old('loyalty_welcome_bonus', $preferences->loyalty_welcome_bonus ?? 0) }}" class="field-input" min="0" max="99999">
                            <span class="field-hint">{{ __('Points awarded when a new customer is registered') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Billing Defaults') }}</div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Default Pricing Mode') }}</label>
                            <select name="default_pricing_mode" class="field-input">
                                <option value="gst_exclusive" {{ ($preferences->default_pricing_mode ?? 'gst_exclusive') === 'gst_exclusive' ? 'selected' : '' }}>{{ __('GST Exclusive') }}</option>
                                <option value="gst_inclusive" {{ ($preferences->default_pricing_mode ?? '') === 'gst_inclusive' ? 'selected' : '' }}>{{ __('GST Inclusive') }}</option>
                                <option value="no_gst"        {{ ($preferences->default_pricing_mode ?? '') === 'no_gst'        ? 'selected' : '' }}>{{ __('No GST') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Default Payment Mode') }}</label>
                            <select name="default_payment_mode" class="field-input">
                                <option value="cash"          {{ ($preferences->default_payment_mode ?? 'cash') === 'cash'          ? 'selected' : '' }}>{{ __('Cash') }}</option>
                                <option value="card"          {{ ($preferences->default_payment_mode ?? '') === 'card'          ? 'selected' : '' }}>{{ __('Card') }}</option>
                                <option value="upi"           {{ ($preferences->default_payment_mode ?? '') === 'upi'           ? 'selected' : '' }}>{{ __('UPI') }}</option>
                                <option value="bank_transfer" {{ ($preferences->default_payment_mode ?? '') === 'bank_transfer' ? 'selected' : '' }}>{{ __('Bank Transfer') }}</option>
                                <option value="cheque"        {{ ($preferences->default_payment_mode ?? '') === 'cheque'        ? 'selected' : '' }}>{{ __('Cheque') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Credit Days') }}</label>
                            <input type="number" name="credit_days" value="{{ old('credit_days', $preferences->credit_days ?? 0) }}" class="field-input" min="0" max="365">
                            <span class="field-hint">{{ __('Default payment due days for credit customers') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Security & Operations') }}</div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Auto Logout (minutes)') }}</label>
                            <input type="number" name="auto_logout_minutes" value="{{ old('auto_logout_minutes', $preferences->auto_logout_minutes ?? 0) }}" class="field-input" min="0" max="480">
                            <span class="field-hint">{{ __('Idle minutes before auto logout. 0 = disabled.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Barcode Prefix') }}</label>
                            <input type="text" name="barcode_prefix" value="{{ old('barcode_prefix', $preferences->barcode_prefix) }}" class="field-input" maxlength="20" placeholder="{{ __('e.g. JF or SHOP1') }}">
                            <span class="field-hint">{{ __('Prefix used when generating product barcodes') }}</span>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                </form>
            @endif

            @if($activeTab === 'roles')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Roles & Permissions') }}</h2>
                    <p class="settings-desc">{{ __('Configure access levels for each role') }}</p>
                </div>
                
                <div class="roles-container">
                    @foreach($roles as $role)
                        <div class="role-card {{ $role->name === 'owner' ? 'locked' : '' }}">
                            <div class="role-head">
                                <span>
                                    @if($role->name === 'owner') 
                                    @elseif($role->name === 'manager') 
                                    @else 
                                    @endif
                                </span>
                                <h4 class="role-title">{{ __($role->display_name) }}</h4>
                                <span class="role-badge">{{ $role->permissions->count() }}</span>
                            </div>
                            
                            @if($role->name === 'owner')
                                <div class="locked-msg">{{ __('All permissions (locked)') }}</div>
                            @else
                                <form method="POST" action="{{ route('settings.update.role', $role) }}">
                                    @csrf
                                    @method('PATCH')
                                    
                                    <div class="role-body">
                                        @foreach($permissionGroups as $group => $groupPerms)
                                            <div class="perm-group">
                                                <div class="perm-group-title">{{ __($group) }}</div>
                                                @foreach($groupPerms as $perm)
                                                    <label class="perm-item">
                                                        <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                                            {{ $role->permissions->contains($perm->id) ? 'checked' : '' }}>
                                                        {{ __($perm->display_name) }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                    
                                    <div class="role-foot">
                                        <button type="submit" class="btn-sm">{{ __('Save') }}</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if($activeTab === 'staff')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Staff Members') }}</h2>
                    <p class="settings-desc">{{ __('Manage your shop\'s employees') }}</p>
                </div>

                @php
                    $atLimit  = $staffLimit !== -1 && $staffCount >= $staffLimit;
                    $pct      = $staffLimit > 0 ? min(100, round($staffCount / $staffLimit * 100)) : 0;
                    $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');
                @endphp

                {{-- Limit usage bar --}}
                <div class="settings-staff-limit-card">
                    <div class="settings-staff-limit-head">
                        <span class="settings-staff-limit-title">{{ __('Staff Accounts') }}</span>
                        <span class="settings-staff-limit-usage">
                            @if($staffLimit === -1)
                                {{ $staffCount }} {{ __('used') }} &nbsp;·&nbsp; <span class="settings-status-unlimited">{{ __('Unlimited') }}</span>
                            @else
                                {{ $staffCount }} / {{ $staffLimit }} {{ __('used') }}
                                @if($atLimit) &nbsp;·&nbsp; <span class="settings-status-limit">{{ __('Limit reached') }}</span>@endif
                            @endif
                        </span>
                    </div>
                    @if($staffLimit !== -1)
                    <div class="settings-progress-track">
                        <div class="settings-progress-fill" style="width:{{ $pct }}%; background:{{ $barColor }};"></div>
                    </div>
                    @endif
                </div>

                <div class="staff-header">
                    <span class="settings-staff-count">{{ $staffCount }} {{ __('non-owner member(s)') }}</span>
                    @if($atLimit)
                        <span class="btn-add settings-btn-disabled" title="{{ __('Staff limit reached. Remove a member or contact your administrator.') }}">
                            <span>+</span> {{ __('Add Staff') }}
                        </span>
                    @else
                        <a href="{{ route('staff.create') }}" class="btn-add">
                            <span>+</span> {{ __('Add Staff') }}
                        </a>
                    @endif
                </div>
                
                @if($staff->count() > 0)
                    <div class="staff-grid">
                        @foreach($staff as $member)
                            <div class="staff-card" data-deletable-row>
                                <div class="staff-avatar">
                                    {{ strtoupper(substr($member->name ?? $member->mobile_number, 0, 1)) }}
                                </div>
                                <div class="staff-info">
                                    <p class="staff-name">{{ $member->name ?? $member->mobile_number }}</p>
                                    <p class="staff-meta">
                                        @if($member->name){{ $member->mobile_number }} · @endif
                                        <span class="staff-role {{ $member->role->name ?? 'staff' }}">
                                            {{ __($member->role->display_name ?? 'Staff') }}
                                        </span>
                                    </p>
                                </div>
                                @if($member->role && $member->role->name !== 'owner')
                                    <div class="staff-actions">
                                        <a href="{{ route('staff.edit', $member) }}" title="{{ __('Edit') }}"></a>
                                            <form method="POST" action="{{ route('staff.destroy', $member) }}"
                                                data-confirm-message="{{ __('Delete this staff member?') }}"
                                                data-ajax-delete>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="{{ __('Delete') }}"></button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">
                        <div class="empty-icon"></div>
                        <p class="empty-text">{{ __('No staff members yet') }}</p>
                        @if(!$atLimit)
                        <a href="{{ route('staff.create') }}" class="btn-add">
                            <span>+</span> {{ __('Add Your First Staff') }}
                        </a>
                        @endif
                    </div>
                @endif
            @endif

            @if($activeTab === 'audit')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Audit Log') }}</h2>
                    <p class="settings-desc">{{ __('Track all system activities and changes') }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white shadow-sm border border-gray-200 p-4 rounded-xl">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 text-amber-700 p-2 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Total Logs') }}</p>
                                <p class="text-xl font-semibold text-gray-900">{{ number_format($stats['total'] ?? $logs->total()) }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm border border-gray-200 p-4 rounded-xl">
                        <div class="flex items-center gap-3">
                            <div class="bg-blue-100 text-blue-700 p-2 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Active Users') }}</p>
                                <p class="text-xl font-semibold text-gray-900">{{ $logs->pluck('user_id')->unique()->count() }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm border border-gray-200 p-4 rounded-xl">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 text-amber-700 p-2 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Actions Today') }}</p>
                                <p class="text-xl font-semibold text-gray-900">{{ number_format($stats['today'] ?? 0) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-sm border border-gray-200 overflow-hidden rounded-xl">
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Recent Activity') }}</h2>
                        <p class="text-sm text-gray-500 mt-1">{{ __('Latest actions across the system') }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Time') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('User') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Action') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Entity') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($logs as $l)
                                @php
                                    $time = $l->created_at ? $l->created_at->format('d M Y H:i:s') : '-';
                                    $userName = $l->user->name ?? __('System');
                                    $actionPretty = \Illuminate\Support\Str::headline($l->action);
                                    $entityType = $l->model_type ?? ($l->model ?? null);
                                    $entityLabel = $entityType ? \Illuminate\Support\Str::headline($entityType) : '—';
                                    $object = $entityLabel . ($l->model_id ? ' #' . $l->model_id : '');

                                    $isStructured = is_array($l->data) || is_object($l->data);
                                    $description = trim((string) $l->description);
                                    if ($isStructured) {
                                        $arr = (array) $l->data;
                                        $parts = [];
                                        if (isset($arr['customer_id'])) $parts[] = 'customer_id: ' . $arr['customer_id'];
                                        if (isset($arr['gross'])) $parts[] = 'gross: ' . $arr['gross'];
                                        if (isset($arr['purity'])) $parts[] = 'purity: ' . $arr['purity'] . 'K';
                                        if (isset($arr['fine_gold'])) $parts[] = 'fine: ' . number_format($arr['fine_gold'], 3) . ' g';

                                        $dataPreview = $parts ? implode(', ', $parts) : \Illuminate\Support\Str::limit(json_encode($arr, JSON_UNESCAPED_UNICODE), 80);
                                        $dataFull = json_encode($arr, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
                                    } else {
                                        $dataPreview = \Illuminate\Support\Str::limit((string)$l->data, 80);
                                        $dataFull = (string)$l->data;
                                    }
                                    if (empty($dataPreview)) {
                                        $dataPreview = $description ?: '—';
                                    }
                                    if (empty($dataFull) && !empty($description)) {
                                        $dataFull = $description;
                                    }

                                    $actionColors = [
                                        'create' => 'bg-green-100 text-green-800',
                                        'update' => 'bg-blue-100 text-blue-800',
                                        'delete' => 'bg-red-100 text-red-800',
                                        'login' => 'bg-purple-100 text-purple-800',
                                        'logout' => 'bg-gray-100 text-gray-800',
                                    ];
                                    $actionColor = $actionColors[strtolower($l->action)] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ $time }}</div>
                                        <div class="text-xs text-gray-500">{{ $l->created_at ? $l->created_at->diffForHumans() : '' }}</div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-8 w-8 bg-gray-200 flex items-center justify-center">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                </svg>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900">{{ $userName }}</div>
                                                <div class="text-xs text-gray-500">{{ $l->user ? __('User') : __('System') }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium {{ $actionColor }}">
                                            {{ $actionPretty }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 font-medium">{{ $object }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm text-gray-700 max-w-xs truncate">{{ $dataPreview }}</div>
                                        @if(!empty($dataFull))
                                            <button class="view-json mt-2 inline-flex items-center px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors" data-json="{{ base64_encode($dataFull) }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                                {{ __('View Details') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('No audit logs found.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($logs->hasPages())
                        <div class="px-4 py-3 border-t border-gray-200">
                            {{ $logs->links() }}
                        </div>
                    @endif
                </div>

                <div id="jsonModal" class="fixed inset-0 hidden items-center justify-center bg-black bg-opacity-50 z-50 p-4">
                    <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-[90vh] flex flex-col">
                        <div class="flex items-center justify-between p-6 border-b border-gray-200">
                            <div class="flex items-center gap-3">
                                <div class="bg-amber-100 rounded-lg p-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900">{{ __('Audit Data Details') }}</h3>
                            </div>
                            <div class="flex gap-2">
                                <button id="copyJson" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    {{ __('Copy') }}
                                </button>
                                <button id="closeJson" class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                        <div class="flex-1 overflow-auto p-6">
                            <pre id="jsonContent" class="bg-gray-50 rounded-lg p-4 text-sm font-mono text-gray-800 whitespace-pre-wrap border border-gray-200"></pre>
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener('click', function(e){
                        if (e.target.matches('.view-json') || e.target.closest('.view-json')) {
                            const button = e.target.matches('.view-json') ? e.target : e.target.closest('.view-json');
                            const encoded = button.getAttribute('data-json');
                            const json = encoded ? atob(encoded) : '';
                            document.getElementById('jsonContent').textContent = json;
                            document.getElementById('jsonModal').classList.remove('hidden');
                            document.getElementById('jsonModal').classList.add('flex');
                        }

                        if (e.target && e.target.id === 'closeJson') {
                            document.getElementById('jsonModal').classList.add('hidden');
                            document.getElementById('jsonModal').classList.remove('flex');
                        }

                        if (e.target && e.target.id === 'copyJson') {
                            const text = document.getElementById('jsonContent').textContent || '';
                            navigator.clipboard.writeText(text).then(() => {
                                const btn = e.target;
                                const original = btn.textContent;
                                btn.textContent = '{{ __('Copied!') }}';
                                setTimeout(() => { btn.textContent = original; }, 1500);
                            });
                        }

                        if (e.target && e.target.id === 'jsonModal') {
                            document.getElementById('jsonModal').classList.add('hidden');
                            document.getElementById('jsonModal').classList.remove('flex');
                        }
                    });
                </script>
            @endif

            @if($activeTab === 'website')
                @include('partials.settings.website-tab')
            @endif
        </div>
        </turbo-frame>
    </div>
</div>
@push('scripts')
<script>
// Update active nav state when switching tabs via Turbo Frame
document.querySelectorAll('.settings-nav .nav-item[data-turbo-frame]').forEach(link => {
    link.addEventListener('click', () => {
        document.querySelectorAll('.settings-nav .nav-item').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
    });
});
</script>
<script>
(() => {
    const dropzone = document.getElementById('shop-logo-dropzone');
    const browseBtn = document.getElementById('shop-logo-browse');
    const input = document.getElementById('shop-logo-input');
    const preview = document.getElementById('shop-logo-preview');
    const placeholder = document.getElementById('shop-logo-placeholder');
    const stateText = document.getElementById('shop-logo-state-text');
    const fileName = document.getElementById('shop-logo-file-name');
    const removeCheckbox = document.getElementById('shop-logo-remove');
    const initialPreviewSrc = (preview.getAttribute('src') || '').trim();
    const initialFileName = (fileName.textContent || '').trim();
    const maxFileBytes = 2 * 1024 * 1024;

    if (!dropzone || !browseBtn || !input || !preview || !placeholder || !stateText || !fileName) {
        return;
    }

    const showPlaceholder = (status = 'Not uploaded') => {
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
        stateText.textContent = status;
    };

    const showPreview = (src, status = 'Ready to upload') => {
        preview.src = src;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        stateText.textContent = status;
    };

    const openPicker = () => {
        input.click();
    };

    const applyFile = (file) => {
        if (!file) {
            return;
        }

        if (!String(file.type || '').startsWith('image/')) {
            input.value = '';
            fileName.textContent = '';
            showPlaceholder('Invalid format');
            return;
        }

        if (file.size > maxFileBytes) {
            input.value = '';
            fileName.textContent = '';
            showPlaceholder('File too large (max 2 MB)');
            return;
        }

        fileName.textContent = file.name;
        if (removeCheckbox) {
            removeCheckbox.checked = false;
        }

        const reader = new FileReader();
        reader.onload = (e) => showPreview(e.target?.result || '');
        reader.readAsDataURL(file);
    };

    const syncInputWithFile = (file) => {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (_) {
            // If assignment is blocked, preview still updates but upload falls back to manual pick.
        }
    };

    dropzone.addEventListener('click', (event) => {
        if (event.target.closest('[data-skip-dropzone-click="true"]') || event.target.closest('.logo-remove')) {
            return;
        }
        openPicker();
    });

    browseBtn.addEventListener('click', (event) => {
        event.preventDefault();
        openPicker();
    });

    dropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPicker();
        }
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const file = event.dataTransfer?.files?.[0];
        if (!file) {
            return;
        }

        syncInputWithFile(file);
        applyFile(file);
    });

    input.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0];
        if (!file) {
            fileName.textContent = '';
            if (removeCheckbox && removeCheckbox.checked) {
                showPlaceholder();
                return;
            }
            if (initialPreviewSrc) {
                showPreview(initialPreviewSrc, 'Uploaded');
                fileName.textContent = initialFileName;
            } else {
                showPlaceholder();
            }
            return;
        }

        applyFile(file);
    });

    if (removeCheckbox) {
        removeCheckbox.addEventListener('change', (event) => {
            if (event.target.checked) {
                input.value = '';
                fileName.textContent = '';
                showPlaceholder();
                return;
            }

            if (input.files && input.files[0]) {
                applyFile(input.files[0]);
                return;
            }

            if (initialPreviewSrc) {
                showPreview(initialPreviewSrc, 'Uploaded');
                fileName.textContent = initialFileName;
            } else {
                showPlaceholder();
            }
        });
    }
})();
</script>
@endpush
</x-app-layout>
