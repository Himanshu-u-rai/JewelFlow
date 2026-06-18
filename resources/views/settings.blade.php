<x-app-layout>
<style>
    .settings-shell {
        padding: 16px 24px 24px !important;
    }

    /* Fixed-viewport Master-Detail layout.
       The layout height is locked to the viewport (minus the top chrome + breathing room).
       Nav and content each scroll inside their own column — the page itself never
       scrolls, so the document-level scrollbar never appears/disappears between tabs
       (which is what previously caused the sidebar to shift). */
    .settings-layout {
        display: grid;
        grid-template-columns: 240px 1fr;
        gap: 18px;
        align-items: stretch;
        height: calc(100vh - 156px);
        background: transparent;
        border: none;
        padding: 0;
        box-shadow: none;
    }

    #settings-content {
        display: block;
        width: 100%;
        min-width: 0;
        max-width: 100%;
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }

    /* Sidebar — scrolls inside its own column, never affected by content height. */
    .settings-nav {
        background: #ffffff;
        border: 2px solid #0f766e;
        border-radius: 16px;
        /* Extra right padding keeps the scrollbar track clear of the rounded
           border so its square top/bottom can't poke past the card corners. */
        padding: 10px 6px 10px 10px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        height: 100%;
        min-height: 0;
        overflow-y: auto;
        /* Reserve gutter space so content doesn't shift when the bar appears. */
        scrollbar-gutter: stable;
        box-shadow: 0 14px 24px rgba(15, 23, 42, 0.05);
        /* Firefox: slim, subtle, themed. */
        scrollbar-width: thin;
        scrollbar-color: rgba(15, 118, 110, 0.35) transparent;
    }
    /* WebKit (Chrome/Safari/Edge): a slim, rounded, teal-tinted scrollbar that
       sits inside the card instead of the chunky default with square corners. */
    .settings-nav::-webkit-scrollbar {
        width: 8px;
    }
    .settings-nav::-webkit-scrollbar-track {
        background: transparent;
        /* Inset the track top/bottom so it never reaches the rounded corners. */
        margin: 8px 0;
    }
    .settings-nav::-webkit-scrollbar-thumb {
        background: rgba(15, 118, 110, 0.3);
        border-radius: 9999px;
        /* Transparent border + background-clip makes the thumb visually slimmer
           and floats it off the edge. */
        border: 2px solid transparent;
        background-clip: padding-box;
        transition: background-color 160ms ease;
    }
    .settings-nav:hover::-webkit-scrollbar-thumb {
        background: rgba(15, 118, 110, 0.5);
        background-clip: padding-box;
    }

    /* Apply only to screens 768px wide or smaller */
    @media (max-width: 768px) {
      nav.settings-nav {
        margin-top: 20px;
      }
    }

    .settings-nav .nav-item {
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

    .settings-nav .nav-item:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    .settings-nav .nav-item.active {
        background: #0f766e;
        color: #ffffff;
        box-shadow: 0 10px 18px rgba(15, 118, 110, 0.2);
        border-radius: 9999px;
    }

    .settings-nav .nav-item.active .nav-icon {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
    }

    .settings-nav .nav-icon {
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

    /* Content — owns its scrollbar inside the locked column. */
    .settings-content {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px;
        padding: 24px 28px;
        height: 100%;
        min-height: 0;
        min-width: 0;
        max-width: 100%;
        overflow-y: auto;
        overflow-x: hidden;
        box-shadow: 0 18px 30px rgba(15, 23, 42, 0.06);
    }

    /* Pricing tab — keep horizontal overflow visible so table wrappers and any
       custom dropdown menus can escape sideways, but keep vertical scrolling
       intact so the (often very tall) pricing form is reachable. */
    .content-inner.settings-shell .settings-content.settings-content-pricing {
        width: 100%;
        min-width: 0;
        max-width: 100%;
        overflow-x: visible !important;
        overflow-y: auto !important;
        padding-left: 18px !important;
        padding-right: 18px !important;
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
        grid-template-columns: auto minmax(0, 1fr);
        gap: 16px;
        align-items: center;
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
        position: relative;
        flex-shrink: 0;
        width: 180px;
        height: 180px;
        min-width: 180px;
        border-radius: 16px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: #ffffff;
        display: grid;
        place-items: center;
        overflow: hidden;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .logo-preview {
        display: block;
        width: 100% !important;
        height: 100% !important;
        max-width: none !important;
        max-height: none !important;
        margin: 0 !important;
        object-fit: contain !important;
        object-position: center center !important;
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

    .logo-delete-btn {
        position: absolute;
        top: 12px;
        right: 12px;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 5px 8px;
        border: 1px solid #fecaca;
        border-radius: 9999px;
        background: #fff1f2;
        font-size: 10px;
        line-height: 1;
        font-weight: 700;
        color: #b91c1c;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }

    .logo-delete-btn:hover {
        background: #ffe4e6;
        border-color: #fda4af;
    }

    .logo-delete-btn.is-pending {
        border-color: #fdba74;
        background: #fff7ed;
        color: #c2410c;
    }

    .logo-delete-note {
        margin-top: 6px;
        font-size: 11px;
        color: #b45309;
    }

    .logo-upload-wrap [data-skip-dropzone-click="true"] {
        cursor: default;
    }

    .logo-upload-wrap .logo-browse-btn,
    .logo-upload-wrap .logo-browse-btn *,
    .logo-upload-wrap .logo-delete-btn,
    .logo-upload-wrap .logo-delete-btn * {
        cursor: pointer;
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
    /* Roles tab: full-width stacked cards. Owner is a slim locked banner;
       Manager and Staff are wide cards whose permission groups flow in a
       responsive multi-column grid — everything visible, no inner scroll. */
    .roles-container {
        display: flex;
        flex-direction: column;
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
        padding: 12px 16px;
        background: #f8fafc;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .role-card.locked .role-head {
        background: #fef3c7;
        border-bottom: none;
    }

    .role-title {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .role-badge {
        font-size: 11px;
        padding: 2px 8px;
        background: #e2e8f0;
        border-radius: 9999px;
        color: #475569;
        font-weight: 600;
    }

    .role-badge-spacer { margin-left: auto; }

    /* No max-height / no overflow: the body grows to fit its content. Groups
       lay out in as many columns as the width allows. */
    .role-body {
        padding: 18px 16px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 18px 24px;
        align-items: start;
    }

    .perm-group {
        break-inside: avoid;
    }

    .perm-group-title {
        font-size: 10px;
        font-weight: 700;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(15, 118, 110, 0.12);
    }

    .perm-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 5px 0;
        font-size: 13px;
        color: #334155;
        cursor: pointer;
    }

    .perm-item .perm-item-label { flex: 1; }

    .locked-msg {
        font-size: 13px;
        color: #b45309;
        font-weight: 500;
    }

    .role-foot {
        padding: 12px 16px;
        background: #f8fafc;
        border-top: 1px solid rgba(15, 23, 42, 0.08);
        display: flex;
        justify-content: flex-end;
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

    /* Roles save: a normal-width button in the right-aligned footer. */
    .role-save-btn {
        padding: 8px 22px;
        font-size: 13px;
        font-weight: 600;
        color: #fff;
        background: #0f766e;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .role-save-btn:hover { background: #0b5f5d; }

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
        background: #bfdbfe;
        color: #1d4ed8;
    }

    .staff-role.staff {
        background: #d1d5db;
        color: #374151;
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
        border-radius: 6px;
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
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        justify-content: center;
        min-height: 180px;
        min-width: 0;
        padding-right: 84px;
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

    /* Pill toggle switch with a bouncy sliding knob that carries an eye icon:
       eye = column/option shown (checked), eye-off = hidden (unchecked).
       The checkbox keeps its real semantics (name + hidden value="0" companion);
       only the appearance is replaced via the element + its ::before knob.
       Icons are inline SVG data-URIs so they render without any icon library. */
    /* Selector qualified with `.content-inner input...` so it OUT-specifies the
       global `.content-inner input[type=checkbox] { border-radius: …sm !important }`
       rule in app.css that otherwise forces a square radius on every checkbox.
       (Equal !important → higher specificity wins, hence the input + class.) */
    .content-inner input.settings-toggle-input-lg,
    .content-inner input.settings-toggle-input-md,
    .settings-toggle-input-lg,
    .settings-toggle-input-md {
        appearance: none;
        -webkit-appearance: none;
        position: relative;
        flex-shrink: 0;
        border-radius: 999px !important;
        background: #cbd5e1;
        cursor: pointer;
        transition: background-color 250ms ease;
        outline: none;
    }
    /* The sliding knob (::before) carries the eye-off icon by default. */
    .settings-toggle-input-lg::before,
    .settings-toggle-input-md::before {
        content: "";
        position: absolute;
        top: 50%;
        border-radius: 50%;
        background-color: #ffffff;
        background-repeat: no-repeat;
        background-position: center;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .3);
        /* eye-off (slash) — unchecked = hidden */
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'/%3E%3Cline x1='1' y1='1' x2='23' y2='23'/%3E%3C/svg%3E");
        /* bouncy slide, matching the requested feel */
        transition: transform 500ms cubic-bezier(.26, 2, .46, .71),
                    background-image 250ms ease;
    }
    /* Match the qualified base-rule specificity so the teal "on" colour wins
       over the high-specificity grey base set above. */
    .content-inner input.settings-toggle-input-lg:checked,
    .content-inner input.settings-toggle-input-md:checked,
    .settings-toggle-input-lg:checked,
    .settings-toggle-input-md:checked {
        background: #0f766e;
    }
    /* Checked = shown: knob slides right and shows the open-eye icon. */
    .settings-toggle-input-lg:checked::before,
    .settings-toggle-input-md:checked::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f766e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3C/svg%3E");
    }
    .settings-toggle-input-lg:focus-visible,
    .settings-toggle-input-md:focus-visible {
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .25);
    }

    /* Large switch (52×28, knob 22). */
    .settings-toggle-input-lg {
        width: 52px;
        height: 28px;
    }
    .settings-toggle-input-lg::before {
        width: 22px;
        height: 22px;
        background-size: 13px 13px;
        transform: translate(3px, -50%);
    }
    .settings-toggle-input-lg:checked::before {
        transform: translate(27px, -50%);
    }

    /* Medium switch (46×26, knob 20). */
    .settings-toggle-input-md {
        width: 46px;
        height: 26px;
    }
    .settings-toggle-input-md::before {
        width: 20px;
        height: 20px;
        background-size: 12px 12px;
        transform: translate(3px, -50%);
    }
    .settings-toggle-input-md:checked::before {
        transform: translate(23px, -50%);
    }

    @media (prefers-reduced-motion: reduce) {
        .settings-toggle-input-lg::before,
        .settings-toggle-input-md::before {
            transition: background-image 250ms ease;
        }
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
        /* On mobile, release the fixed-viewport lock so the page can scroll naturally
           (one column, nav on top, content below). The desktop master-detail pattern
           doesn't apply when there's only one column. */
        .settings-layout {
            grid-template-columns: 1fr;
            gap: 14px;
            min-width: 0;
            max-width: 100%;
            height: auto;
        }

        #settings-content {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            height: auto;
            overflow: visible;
        }

        .settings-content {
            height: auto;
            overflow: visible;
        }

        .settings-nav {
            position: static;
            flex-direction: row;
            flex-wrap: nowrap;
            height: auto;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 8px;
            scrollbar-width: none;
        }

        .settings-nav .nav-item {
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

        .logo-upload-meta-col {
            padding-right: 0;
        }

        .role-body {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 600px) {
        .settings-shell {
            padding: 0 12px 16px !important;
        }

        .settings-content {
            padding: 18px;
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }

        .settings-content-pricing {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            padding-inline: 0;
            overflow-x: visible !important;
            overflow-y: visible !important;
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

        .logo-delete-btn {
            top: 10px;
            right: 10px;
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

    /* ──────────────────────────────────────────────────────────────
       Plan & Billing tab — ported verbatim from subscription/status.blade.php
       so the `sub-*` styled cards render identically inside the Settings tab.
       Scoped to .sub-status-page; does not touch any other settings surface.
       ────────────────────────────────────────────────────────────── */
    .sub-status-page {
        --sub-border: #e7ebf1;
        --sub-border-soft: #eef1f6;
        --sub-border-strong: #d9dfe8;
        --sub-surface: #ffffff;
        --sub-ink: #0f172a;
        --sub-ink-2: #3d4861;
        --sub-muted: #6a7588;
        --sub-accent: #0d9488;
        --sub-accent-deep: #0f766e;
        --sub-accent-soft: rgba(13, 148, 136, 0.08);
        --sub-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 10px 28px -20px rgba(16, 24, 40, 0.20);
        --sub-ease: cubic-bezier(0.23, 1, 0.32, 1);
    }

    .sub-status-page .sub-status-wrap {
        display: flex;
        flex-direction: column;
        gap: 28px;
    }

    @media (prefers-reduced-motion: no-preference) {
        .sub-status-page .sub-hero,
        .sub-status-page .sub-grid > *,
        .sub-status-page .sub-billing-section {
            animation: subRise 0.5s var(--sub-ease) both;
        }
        .sub-status-page .sub-grid > *:nth-child(2) { animation-delay: 0.05s; }
        .sub-status-page .sub-billing-section { animation-delay: 0.1s; }
        @keyframes subRise {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    }

    .sub-status-page .sub-hero,
    .sub-status-page .sub-card {
        border: 1px solid var(--sub-border);
        border-radius: 16px;
        background: var(--sub-surface);
        box-shadow: var(--sub-shadow);
    }

    .sub-status-page .sub-hero {
        padding: 28px 28px 26px;
    }

    .sub-status-page .sub-hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 26px;
    }

    .sub-status-page .sub-plan-name {
        margin: 0;
        color: var(--sub-ink);
        font-size: 28px;
        font-weight: 700;
        line-height: 1.12;
        letter-spacing: -0.02em;
        text-wrap: balance;
    }

    .sub-status-page .sub-plan-copy {
        margin: 8px 0 0;
        max-width: 58ch;
        color: var(--sub-muted);
        font-size: 14px;
        line-height: 1.6;
        text-wrap: pretty;
    }

    .sub-status-page .sub-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 30px;
        padding: 0 13px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.01em;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .sub-status-page .sub-status-pill::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 999px;
        background: currentColor;
        box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 18%, transparent);
    }

    .sub-status-page .sub-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-bottom: 26px;
        border: 1px solid var(--sub-border);
        border-radius: 12px;
        overflow: hidden;
    }

    .sub-status-page .sub-kpi {
        padding: 16px 18px;
        border-right: 1px solid var(--sub-border);
    }

    .sub-status-page .sub-kpi:last-child {
        border-right: 0;
    }

    .sub-status-page .sub-kpi-label {
        margin: 0 0 7px;
        color: var(--sub-muted);
        font-size: 12px;
        font-weight: 500;
        letter-spacing: 0;
        text-transform: none;
    }

    .sub-status-page .sub-kpi-value {
        margin: 0;
        color: var(--sub-ink);
        font-size: 19px;
        font-weight: 650;
        line-height: 1.2;
        letter-spacing: -0.01em;
        font-variant-numeric: tabular-nums;
    }

    .sub-status-page .sub-health {
        border-top: 1px solid var(--sub-border-soft);
        padding-top: 22px;
    }

    .sub-status-page .sub-health-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .sub-status-page .sub-health-title {
        margin: 0;
        color: var(--sub-ink);
        font-size: 15px;
        font-weight: 600;
        line-height: 1.3;
    }

    .sub-status-page .sub-health-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 26px;
        padding: 0 11px;
        border-radius: 999px;
        border: 1px solid #cfe6e2;
        background: var(--sub-accent-soft);
        color: #0f766e;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .sub-status-page .sub-health-copy {
        margin: 0;
        color: var(--sub-muted);
        font-size: 13.5px;
        line-height: 1.6;
    }

    .sub-status-page .sub-progress-track {
        width: 100%;
        height: 6px;
        margin-top: 14px;
        border-radius: 999px;
        background: #eceff4;
        overflow: hidden;
    }

    .sub-status-page .sub-progress-fill {
        height: 100%;
        border-radius: 999px;
        background: var(--sub-accent);
        transform-origin: left center;
    }

    @media (prefers-reduced-motion: no-preference) {
        .sub-status-page .sub-progress-fill {
            animation: subFill 0.7s var(--sub-ease) 0.08s both;
        }
        @keyframes subFill {
            from { transform: scaleX(0); }
            to   { transform: scaleX(1); }
        }
    }

    .sub-status-page .sub-grid {
        display: grid;
        grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
        gap: 20px;
    }

    .sub-status-page .sub-card-head {
        padding: 22px 24px 0;
    }

    .sub-status-page .sub-card-title {
        margin: 0;
        color: var(--sub-ink);
        font-size: 16px;
        font-weight: 600;
        line-height: 1.25;
        letter-spacing: -0.01em;
    }

    .sub-status-page .sub-card-copy {
        margin: 5px 0 0;
        color: var(--sub-muted);
        font-size: 13px;
        line-height: 1.55;
    }

    .sub-status-page .sub-card-body {
        padding: 16px 24px 22px;
    }

    .sub-status-page .sub-detail-list {
        display: grid;
    }

    .sub-status-page .sub-detail-item {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 16px;
        padding: 13px 0;
        border-bottom: 1px solid var(--sub-border-soft);
    }

    .sub-status-page .sub-detail-item:first-child { padding-top: 4px; }

    .sub-status-page .sub-detail-item:last-child {
        border-bottom: 0;
        padding-bottom: 4px;
    }

    .sub-status-page .sub-detail-label {
        margin: 0;
        color: var(--sub-muted);
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0;
        text-transform: none;
        flex-shrink: 0;
    }

    .sub-status-page .sub-detail-value {
        margin: 0;
        color: var(--sub-ink);
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.45;
        text-align: right;
    }

    .sub-status-page .sub-note {
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid var(--sub-border-soft);
        color: var(--sub-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    .sub-status-page .sub-feature-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 28px;
    }

    .sub-status-page .sub-feature-item {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        padding: 11px 0;
        border-bottom: 1px solid var(--sub-border-soft);
        color: var(--sub-ink-2);
        font-size: 13.5px;
        font-weight: 500;
        line-height: 1.45;
    }

    .sub-status-page .sub-feature-item:last-child,
    .sub-status-page .sub-feature-item:nth-last-child(2):nth-child(odd) {
        border-bottom: 0;
    }

    .sub-status-page .sub-dot {
        width: 16px;
        height: 16px;
        color: var(--sub-accent);
        flex-shrink: 0;
    }

    .sub-status-page .sub-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 0 17px;
        border: 1px solid transparent;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.01em;
        text-decoration: none;
        transition: transform 0.16s var(--sub-ease), background-color 0.16s var(--sub-ease), border-color 0.16s var(--sub-ease), box-shadow 0.16s var(--sub-ease);
    }

    .sub-status-page .sub-btn:focus-visible {
        outline: 2px solid var(--sub-accent);
        outline-offset: 2px;
    }

    .sub-status-page .sub-btn:active {
        transform: scale(0.97);
    }

    .sub-status-page .sub-btn.primary {
        background: var(--sub-accent, #0d9488);
        color: #fff;
        box-shadow: 0 1px 2px rgba(13, 148, 136, 0.22);
    }

    .sub-status-page .sub-btn.primary:hover {
        background: var(--sub-accent-deep, #0f766e);
    }

    .sub-status-page .sub-btn.secondary {
        background: #fff;
        color: var(--sub-ink, #0f172a);
        border-color: var(--sub-border-strong, #d9dfe8);
    }

    .sub-status-page .sub-btn.secondary:hover {
        background: #f7f9fc;
        border-color: #c5cedb;
    }

    @media (max-width: 1100px) {
        .sub-status-page .sub-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .sub-status-page .sub-kpi {
            border-bottom: 1px solid var(--sub-border);
        }
        .sub-status-page .sub-kpi:nth-child(2n) { border-right: 0; }
        .sub-status-page .sub-kpi:nth-child(n+3) { border-bottom: 0; }

        .sub-status-page .sub-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767px) {
        .sub-status-page .sub-hero {
            padding: 20px;
        }

        .sub-status-page .sub-hero-top {
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .sub-status-page .sub-plan-name {
            font-size: 23px;
        }

        .sub-status-page .sub-plan-copy {
            font-size: 13px;
        }

        .sub-status-page .sub-kpi-grid {
            grid-template-columns: 1fr;
            margin-bottom: 22px;
        }

        .sub-status-page .sub-kpi {
            border-right: 0;
            border-bottom: 1px solid var(--sub-border);
        }
        .sub-status-page .sub-kpi:last-child { border-bottom: 0; }

        .sub-status-page .sub-health-head {
            align-items: flex-start;
        }

        .sub-status-page .sub-card-head {
            padding: 20px 18px 0;
        }

        .sub-status-page .sub-card-body {
            padding: 14px 18px 20px;
        }

        .sub-status-page .sub-feature-list {
            grid-template-columns: 1fr;
        }

        .sub-status-page .sub-feature-item:nth-last-child(2):nth-child(odd) {
            border-bottom: 1px solid var(--sub-border-soft);
        }
        .sub-status-page .sub-feature-item:last-child {
            border-bottom: 0;
        }
    }

    /* ─── Billing History section ─── */
    .sub-status-page .sub-billing-head {
        margin-bottom: 16px;
    }
    .sub-status-page .sub-billing-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--sub-ink);
        margin: 0 0 5px;
        letter-spacing: -0.01em;
    }
    .sub-status-page .sub-billing-copy {
        font-size: 13px;
        color: var(--sub-muted);
        margin: 0;
    }
    .sub-status-page .sub-billing-card {
        background: #ffffff;
        border: 1px solid var(--sub-border);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--sub-shadow);
    }
    .sub-status-page .sub-billing-table-wrap {
        overflow-x: auto;
    }
    .sub-status-page .sub-billing-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .sub-status-page .sub-billing-table thead th {
        padding: 13px 18px;
        text-align: left;
        font-weight: 500;
        font-size: 12px;
        color: var(--sub-muted);
        background: #fafbfd;
        border-bottom: 1px solid var(--sub-border);
        text-transform: none;
        letter-spacing: 0;
        white-space: nowrap;
    }
    .sub-status-page .sub-billing-table thead th.text-right { text-align: right; }
    .sub-status-page .sub-billing-table tbody td {
        padding: 13px 18px;
        border-bottom: 1px solid var(--sub-border-soft);
        color: var(--sub-ink-2);
        vertical-align: middle;
    }
    .sub-status-page .sub-billing-table tbody tr:last-child td { border-bottom: 0; }
    .sub-status-page .sub-billing-table tbody tr:hover { background: #fafbfd; }
    .sub-status-page .sub-billing-table td.text-right { text-align: right; }
    .sub-status-page .sub-billing-num {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        font-weight: 600;
        color: var(--sub-ink);
    }
    .sub-status-page .sub-billing-capitalize { text-transform: capitalize; color: var(--sub-ink-2); }
    .sub-status-page .sub-billing-muted { color: var(--sub-muted); font-size: 12.5px; }
    .sub-status-page .sub-billing-amount { font-weight: 600; color: var(--sub-ink); font-variant-numeric: tabular-nums; }
    .sub-status-page .sub-billing-pill {
        display: inline-flex;
        align-items: center;
        padding: 2px 10px;
        border-radius: 9999px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.6;
    }
    .sub-status-page .sub-billing-pill-paid {
        background: #ecfdf5; color: #065f46; box-shadow: inset 0 0 0 1px #a7f3d0;
    }
    .sub-status-page .sub-billing-pill-cancelled {
        background: #fef2f2; color: #991b1b; box-shadow: inset 0 0 0 1px #fecaca;
    }
    .sub-status-page .sub-billing-view {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--sub-accent-deep);
        text-decoration: none;
    }
    .sub-status-page .sub-billing-view:hover { color: var(--sub-accent); text-decoration: underline; }
    .sub-status-page .sub-billing-empty {
        text-align: center;
        color: var(--sub-muted);
        padding: 32px 16px !important;
        font-size: 13px;
    }
    .sub-status-page .sub-billing-pagination {
        padding: 14px 18px;
        border-top: 1px solid var(--sub-border-soft);
        background: #fafbfd;
    }

    @media (max-width: 767px) {
        .sub-status-page .sub-billing-table-wrap { overflow-x: visible; }
        .sub-status-page .sub-billing-table thead { display: none; }
        .sub-status-page .sub-billing-table,
        .sub-status-page .sub-billing-table tbody,
        .sub-status-page .sub-billing-table tr,
        .sub-status-page .sub-billing-table td {
            display: block;
            width: 100%;
        }
        .sub-status-page .sub-billing-table tr {
            padding: 4px 16px 12px;
            border-bottom: 8px solid #f1f5f9;
        }
        .sub-status-page .sub-billing-table tbody tr:last-child { border-bottom: 0; }
        .sub-status-page .sub-billing-table td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid #f4f7fb;
            text-align: right;
            white-space: normal;
        }
        .sub-status-page .sub-billing-table td:last-child { border-bottom: 0; }
        .sub-status-page .sub-billing-table td[data-label]::before {
            content: attr(data-label);
            flex-shrink: 0;
            text-align: left;
            color: #7d8aa3;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .sub-status-page .sub-billing-table td.sub-billing-action { padding-top: 12px; }
        .sub-status-page .sub-billing-table td.sub-billing-action::before { display: none; }
        .sub-status-page .sub-billing-view {
            display: block;
            width: 100%;
            text-align: center;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--sub-border-strong);
            background: #f6f9fc;
            color: var(--sub-accent-deep);
        }
        .sub-status-page .sub-billing-view:hover { background: #eef4fb; text-decoration: none; }
        .sub-status-page .sub-billing-table td.sub-billing-empty {
            display: block;
            text-align: center;
            padding: 28px 16px !important;
        }
    }
</style>

<x-page-header class="settings-page-header">
    <div>
        <h1 class="page-title">{{ __('Settings') }}</h1>
    </div>
</x-page-header>

<div class="content-inner settings-shell">
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
            {{-- General — account info, available to any authenticated user --}}
            <a href="{{ route('settings.edit', ['tab' => 'general']) }}" class="nav-item {{ $activeTab === 'general' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span> {{ __('General') }}
            </a>
            @can('settings.edit')
            <a href="{{ route('settings.edit', ['tab' => 'profile']) }}" class="nav-item {{ $activeTab === 'profile' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span> {{ __('Profile') }}
            </a>
            @endcan
            {{-- Shop Info / Invoice / Payment Methods / Preferences — settings.view to read, settings.edit to write --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'shop']) }}" class="nav-item {{ $activeTab === 'shop' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span> {{ __('Shop Info') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'billing']) }}" class="nav-item {{ $activeTab === 'billing' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span> {{ __('Invoice') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'gst']) }}" class="nav-item {{ $activeTab === 'gst' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6-6"/><circle cx="9.5" cy="8.5" r="1.5"/><circle cx="14.5" cy="13.5" r="1.5"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg></span> {{ __('GST & Tax') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'subscription']) }}" class="nav-item {{ $activeTab === 'subscription' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4Z"/><path d="M4 6v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/><path d="M12 12v4h4"/></svg></span> {{ __('Plan & Billing') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'payment-methods']) }}" class="nav-item {{ $activeTab === 'payment-methods' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span> {{ __('Payment Methods') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'preferences']) }}" class="nav-item {{ $activeTab === 'preferences' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span> {{ __('Preferences') }}
            </a>
            <a href="{{ route('settings.edit', ['tab' => 'return-policy']) }}" class="nav-item {{ $activeTab === 'return-policy' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></span> {{ __('Return Policy') }}
            </a>
            @endcan
            {{-- Pricing — only users with pricing.update --}}
            @if($shop->isRetailer())
            @can('pricing.update')
            <a href="{{ route('settings.edit', ['tab' => 'pricing']) }}" class="nav-item {{ $activeTab === 'pricing' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/><path d="M12 1a5 5 0 0 0 0 22"/></svg></span> {{ __('Pricing') }}
            </a>
            @endcan
            @endif
            {{-- Materials — which metals this shop offers --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'materials']) }}" class="nav-item {{ $activeTab === 'materials' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M7 12h10"/></svg></span> {{ __('Materials') }}
            </a>
            @endcan
            {{-- Catalog Website — same as Settings: needs settings.view to enter --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'website']) }}" class="nav-item {{ $activeTab === 'website' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span> {{ __('Catalog Website') }}
            </a>
            @endcan
            {{-- Roles & Permissions — Owner only (the matrix editor itself uses role:owner) --}}
            @if(auth()->user()->isOwner())
            <a href="{{ route('settings.edit', ['tab' => 'roles']) }}" class="nav-item {{ $activeTab === 'roles' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span> {{ __('Roles') }}
            </a>
            @endif
            {{-- Staff — staff.view to read --}}
            @can('staff.view')
            <a href="{{ route('settings.edit', ['tab' => 'staff']) }}" class="nav-item {{ $activeTab === 'staff' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> {{ __('Staff') }}
            </a>
            @endcan
            {{-- Audit Log — settings.view (sensitive history) --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'audit']) }}" class="nav-item {{ $activeTab === 'audit' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span> {{ __('Audit Log') }}
            </a>
            @endcan
            {{-- Services — settings.view --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'services']) }}" class="nav-item {{ $activeTab === 'services' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span> {{ __('Business Editions') }}
            </a>
            @endcan
            {{-- Devices — settings.view (mobile phones signed in to this shop) --}}
            @can('settings.view')
            <a href="{{ route('settings.edit', ['tab' => 'devices']) }}" class="nav-item {{ $activeTab === 'devices' ? 'active' : '' }}" data-turbo-frame="settings-content">
                <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span> {{ __('Devices') }}
            </a>
            @endcan
        </nav>

        <!-- Content Area -->
        <turbo-frame id="settings-content">
        <div class="settings-content {{ $activeTab === 'pricing' ? 'settings-content-pricing' : '' }}">
            @php
                // Used to hide Save buttons + show a "View only" banner on tabs
                // the user can read but not write (Shop Info / Invoice / Preferences).
                $canEditSettings = auth()->user()->can('settings.edit');
            @endphp

            @if(! $canEditSettings && in_array($activeTab, ['shop', 'billing', 'preferences', 'website']))
                <div class="settings-readonly-banner" style="display:flex;align-items:center;gap:10px;padding:12px 16px;margin-bottom:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;font-size:13px;font-weight:600;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>{{ __('View only — ask the shop owner to grant') }} <code style="background:rgba(217,119,6,0.1);padding:1px 6px;border-radius:4px;font-family:ui-monospace,monospace;font-size:12px;">settings.edit</code> {{ __('if you need to make changes here.') }}</span>
                </div>
            @endif

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
                            <span class="font-semibold text-gray-900">{{ auth()->user()->role?->display_name ?? __('Guest') }}</span>
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
                        <form method="POST" action="{{ route('logout') }}" data-turbo-frame="_top">
                            @csrf
                            <button type="submit" class="btn btn-danger"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>{{ __('Logout') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @can('settings.edit')
            @if($activeTab === 'profile')
                @php $user = auth()->user(); @endphp
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Profile') }}</h2>
                    <p class="settings-desc">{{ __('Your name, email, and password') }}</p>
                </div>

                @include('profile.partials.update-profile-information-form')

                <div class="section-divider"></div>

                <section>
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">{{ __('Mobile Number') }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Your registered mobile number used to log in.') }}</p>
                    </header>
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span class="text-sm font-medium text-gray-800">{{ auth()->user()->mobile_number ?? '—' }}</span>
                        <a href="{{ route('profile.mobile.change') }}" data-turbo-frame="_top" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">{{ __('Change') }}</a>
                    </div>
                </section>

                <div class="section-divider"></div>
                @include('profile.partials.update-password-form')

                <div class="section-divider"></div>
                @include('profile.partials.delete-user-form')
            @endif
            @endcan

            @if($activeTab === 'shop')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Shop Information') }}</h2>
                    <p class="settings-desc">{{ __('Business identity and tax configuration') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.shop') }}" enctype="multipart/form-data" data-turbo-frame="_top">
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
                                        <input id="shop-logo-remove" type="hidden" name="remove_logo" value="{{ old('remove_logo', 0) ? 1 : 0 }}">
                                        <button
                                            type="button"
                                            id="shop-logo-delete-btn"
                                            class="logo-delete-btn {{ old('remove_logo', 0) ? 'is-pending' : '' }}"
                                            data-skip-dropzone-click="true"
                                            aria-pressed="{{ old('remove_logo', 0) ? 'true' : 'false' }}"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                            <span id="shop-logo-delete-label">{{ old('remove_logo', 0) ? __('Undo') : __('Delete') }}</span>
                                        </button>
                                        <div id="shop-logo-delete-note" class="logo-delete-note" @if(!old('remove_logo', 0)) style="display:none;" @endif>
                                            {{ __('Logo will be deleted after you save changes.') }}
                                        </div>
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
                    
                    {{-- GST Number, GST Rate, HSN & IGST moved to the dedicated "GST & Tax" tab. --}}
                    @if(auth()->user()->shop?->isManufacturer())
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Wastage Recovery (%)') }}</label>
                            <input type="number" name="wastage_recovery_percent" value="{{ old('wastage_recovery_percent', $shop->wastage_recovery_percent) }}" class="field-input" step="0.01" min="0" required>
                        </div>
                    </div>
                    @endif

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Owner Details') }}</div>
                    <p class="settings-section-subtitle">{{ __("The shop's registered owner. This is a business detail and may appear on documents — it is separate from your login name in the Profile tab.") }}</p>

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
                    
                    @if($canEditSettings)
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                    @endif
                </form>
            @endif

            @if($activeTab === 'gst')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('GST & Tax') }}</h2>
                    <p class="settings-desc">{{ __('Your GSTIN, default GST rate, per-metal rates, HSN codes and tax type — all in one place.') }}</p>
                </div>

                <form method="POST" action="{{ route('settings.update.gst') }}" data-turbo-frame="_top">
                    @csrf
                    @method('PATCH')
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('GST Number (GSTIN)') }}</label>
                            <input type="text" name="gst_number" value="{{ old('gst_number', $shop->gst_number) }}" class="field-input" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Default GST Rate (%)') }}</label>
                            <input type="number" name="gst_rate" value="{{ old('gst_rate', $shop->gst_rate) }}" class="field-input" step="0.01" min="0" max="100" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('HSN — Gold') }}</label>
                            <input type="text" name="hsn_gold" value="{{ old('hsn_gold', $billing->hsn_gold ?? '7113') }}" class="field-input" maxlength="20">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('HSN — Silver') }}</label>
                            <input type="text" name="hsn_silver" value="{{ old('hsn_silver', $billing->hsn_silver ?? '7113') }}" class="field-input" maxlength="20">
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('HSN — Diamond') }}</label>
                            <input type="text" name="hsn_diamond" value="{{ old('hsn_diamond', $billing->hsn_diamond ?? '7114') }}" class="field-input" maxlength="20">
                        </div>
                    </div>

                    {{-- Platinum / Copper HSN — only shown when the shop has enabled
                         that metal in Materials (otherwise the field is irrelevant). --}}
                    @if(in_array('platinum', $gstEnabledMetals ?? [], true) || in_array('copper', $gstEnabledMetals ?? [], true))
                    <div class="form-row">
                        @if(in_array('platinum', $gstEnabledMetals ?? [], true))
                        <div class="field">
                            <label class="field-label">{{ __('HSN — Platinum') }}</label>
                            <input type="text" name="hsn_platinum" value="{{ old('hsn_platinum', $billing->hsn_platinum ?? '7115') }}" class="field-input" maxlength="20">
                        </div>
                        @endif
                        @if(in_array('copper', $gstEnabledMetals ?? [], true))
                        <div class="field">
                            <label class="field-label">{{ __('HSN — Copper') }}</label>
                            <input type="text" name="hsn_copper" value="{{ old('hsn_copper', $billing->hsn_copper ?? '7403') }}" class="field-input" maxlength="20">
                        </div>
                        @endif
                    </div>
                    @endif

                    <div class="form-row">
                        <label class="field-label" style="display:flex; gap:10px; align-items:center;">
                            <input type="hidden" name="igst_mode" value="0">
                            <input type="checkbox" name="igst_mode" value="1" {{ old('igst_mode', $billing->igst_mode ?? false) ? 'checked' : '' }}
                                   class="settings-toggle-input-lg">
                            {{ __('Interstate (IGST) — show one IGST line instead of CGST + SGST on the invoice') }}
                        </label>
                    </div>

                    @if($canEditSettings)
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save GST Settings') }}</button>
                    </div>
                    @endif
                </form>

                <div class="section-divider"></div>

                {{-- Per-metal GST rates: optional overrides on top of the Default GST Rate above --}}
                <div id="gst-categories" class="settings-header" style="margin-top:4px;">
                    <h2 class="settings-title">{{ __('Per-Metal GST Rates') }}</h2>
                    <p class="settings-desc">{{ __('Optional. Charge a different GST rate for specific metals. Any metal without an override uses the Default GST Rate above.') }}</p>
                </div>

                @forelse($gstCategories as $cat)
                    <div class="form-row" style="align-items:flex-end; gap:8px;">
                        <form method="POST" action="{{ route('settings.gst-categories.update', $cat) }}" data-turbo-frame="_top" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; flex:1;">
                            @csrf
                            @method('PATCH')
                            <div class="field" style="min-width:140px;">
                                <label class="field-label">{{ __('Label') }}</label>
                                <input type="text" name="name" value="{{ $cat->name }}" class="field-input" maxlength="80" required>
                            </div>
                            <div class="field" style="min-width:120px;">
                                <label class="field-label">{{ __('Metal') }}</label>
                                <select name="metal_type" class="field-input">
                                    <option value="">{{ __('All / Default') }}</option>
                                    @foreach($gstEnabledMetals as $m)
                                        <option value="{{ $m }}" @selected($cat->metal_type === $m)>{{ ucfirst($m) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="min-width:90px;">
                                <label class="field-label">{{ __('GST %') }}</label>
                                <input type="number" name="rate_pct" value="{{ rtrim(rtrim((string) $cat->rate_pct, '0'), '.') }}" class="field-input" step="0.01" min="0" max="99.99" required>
                            </div>
                            <label class="field-label" style="display:flex; gap:4px; align-items:center; white-space:nowrap; margin-bottom:8px;">
                                <input type="checkbox" name="is_default" value="1" @checked($cat->is_default)> {{ __('Default') }}
                            </label>
                            @if($canEditSettings)
                                <button type="submit" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                            @endif
                        </form>
                        @if($canEditSettings)
                            <form method="POST" action="{{ route('settings.gst-categories.destroy', $cat) }}" data-turbo-frame="_top" onsubmit="return confirm('{{ __('Delete this GST rate?') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">{{ __('Delete') }}</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="settings-desc">{{ __('No per-metal overrides yet. All metals use the Default GST Rate.') }}</p>
                @endforelse

                @if($canEditSettings)
                    <div class="section-divider"></div>
                    <form method="POST" action="{{ route('settings.gst-categories.store') }}" data-turbo-frame="_top">
                        @csrf
                        <div class="form-row" style="align-items:flex-end; gap:8px;">
                            <div class="field" style="min-width:140px;">
                                <label class="field-label">{{ __('Label') }}</label>
                                <input type="text" name="name" class="field-input" maxlength="80" placeholder="{{ __('e.g. Gold GST') }}" required>
                            </div>
                            <div class="field" style="min-width:120px;">
                                <label class="field-label">{{ __('Metal') }}</label>
                                <select name="metal_type" class="field-input">
                                    <option value="">{{ __('All / Default') }}</option>
                                    @foreach($gstEnabledMetals as $m)
                                        <option value="{{ $m }}">{{ ucfirst($m) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="min-width:90px;">
                                <label class="field-label">{{ __('GST %') }}</label>
                                <input type="number" name="rate_pct" class="field-input" step="0.01" min="0" max="99.99" placeholder="3.00" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">{{ __('Add GST Rate') }}</button>
                        </div>
                    </form>
                @endif
            @endif

            @if($activeTab === 'billing')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Invoice Settings') }}</h2>
                    <p class="settings-desc">{{ __('Customize invoice appearance and payment details') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.billing') }}" enctype="multipart/form-data" data-turbo-frame="_top">
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
                            <textarea name="terms_and_conditions" class="field-input" rows="6" placeholder="{{ __('One point per line. Max 6 lines.') }}">{{ old('terms_and_conditions', $billing->terms_and_conditions ?: implode("\n", \App\Models\ShopBillingSettings::defaultTerms())) }}</textarea>
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

                    {{-- Tax Settings (IGST display + HSN codes) moved to the dedicated GST & Tax tab. --}}

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

                    @if($canEditSettings)
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                    @endif
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

            @if($activeTab === 'payment-methods')
                @include('partials.settings.payment-methods-tab')
            @endif

            @if($activeTab === 'preferences')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Preferences') }}</h2>
                    <p class="settings-desc">{{ __('Display and formatting options') }}</p>
                </div>
                
                <form method="POST" action="{{ route('settings.update.preferences') }}" data-turbo-frame="_top">
                    @csrf
                    @method('PATCH')
                    
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Low Stock Alert') }}</label>
                            <input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $preferences->low_stock_threshold) }}" class="field-input" min="0" required>
                        </div>
                    </div>

                    <div class="settings-section-top">
                        <h3 class="settings-section-title">{{ __('Loyalty Points') }}</h3>
                        <p class="settings-section-subtitle">{{ __('Configure how customers earn and lose points') }}</p>
                    </div>

                    <div class="form-row cols-3">
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
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Inventory Display') }}</div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('Stock Price Display') }}</label>
                            <select name="stock_value_display" class="field-input">
                                <option value="total"    {{ ($preferences->stock_value_display ?? 'total') === 'total'    ? 'selected' : '' }}>{{ __('Full Price (₹ total value per item)') }}</option>
                                <option value="per_gram" {{ ($preferences->stock_value_display ?? 'total') === 'per_gram' ? 'selected' : '' }}>{{ __('Per Gram Rate (₹/g)') }}</option>
                            </select>
                            <span class="field-hint">{{ __('Controls how item prices appear in the inventory list.') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Language') }}</div>
                    <div class="form-row">
                        <div class="field">
                            <label class="field-label">{{ __('App Language') }}</label>
                            @php $currentLang = old('language', $preferences->language ?? config('app.locale', 'en')); @endphp
                            <select name="language" class="field-input">
                                @foreach(config('app.supported_locales', ['en' => 'English']) as $code => $label)
                                    <option value="{{ $code }}" {{ $currentLang === $code ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <span class="field-hint">{{ __('Language for the app interface. Hindi coverage is still being expanded — some screens may stay in English for now.') }}</span>
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
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Invoice Rounding') }}</div>
                    <div class="form-row cols-3">
                        <div class="field">
                            <label class="field-label">{{ __('Rounding Method') }}</label>
                            <select name="rounding_method" class="field-input">
                                <option value="none"     {{ ($preferences->rounding_method ?? 'none') === 'none'     ? 'selected' : '' }}>{{ __('No rounding (paise-accurate)') }}</option>
                                <option value="normal"   {{ ($preferences->rounding_method ?? 'none') === 'normal'   ? 'selected' : '' }}>{{ __('Round to nearest') }}</option>
                                <option value="upward"   {{ ($preferences->rounding_method ?? 'none') === 'upward'   ? 'selected' : '' }}>{{ __('Always round up') }}</option>
                                <option value="downward" {{ ($preferences->rounding_method ?? 'none') === 'downward' ? 'selected' : '' }}>{{ __('Always round down') }}</option>
                            </select>
                            <span class="field-hint">{{ __("How the invoice total is rounded. Tally-equivalent: Normal / Upward / Downward. Default keeps current paise-accurate behaviour.") }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Round to Nearest') }}</label>
                            <select name="round_off_nearest" class="field-input">
                                <option value="1"  {{ (int)($preferences->round_off_nearest ?? 1) === 1  ? 'selected' : '' }}>{{ __('1 Rupee') }}</option>
                                <option value="5"  {{ (int)($preferences->round_off_nearest ?? 1) === 5  ? 'selected' : '' }}>{{ __('5 Rupees') }}</option>
                                <option value="10" {{ (int)($preferences->round_off_nearest ?? 1) === 10 ? 'selected' : '' }}>{{ __('10 Rupees') }}</option>
                            </select>
                            <span class="field-hint">{{ __("Total is rounded to this nearest amount. Has no effect when method is 'No rounding'.") }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Max Manual Discount %') }}</label>
                            <input type="number" name="max_manual_discount_percent"
                                   value="{{ old('max_manual_discount_percent', $preferences->max_manual_discount_percent) }}"
                                   class="field-input" step="0.01" min="0" max="100"
                                   placeholder="{{ __('No cap') }}">
                            <span class="field-hint">{{ __('Cap on the manual discount a cashier can apply. Leave empty for no cap. Example: enter 10 to limit cashiers to 10% off any sale.') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Compliance (KYC / Rule 114B)') }}</div>
                    <div class="form-row cols-2">
                        <div class="field">
                            <label class="field-label">{{ __('Enable Compliance Checks') }}</label>
                            <select name="compliance_enabled" class="field-input">
                                <option value="1" {{ ($preferences->compliance_enabled ?? false) ? 'selected' : '' }}>{{ __('Enabled') }}</option>
                                <option value="0" {{ !($preferences->compliance_enabled ?? false) ? 'selected' : '' }}>{{ __('Disabled') }}</option>
                            </select>
                            <span class="field-hint">{{ __('When enabled, PAN and address are required for transactions above the threshold.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('High-Value Threshold (₹)') }}</label>
                            <input type="number" name="compliance_threshold"
                                   value="{{ old('compliance_threshold', $preferences->compliance_threshold ?? 200000) }}"
                                   class="field-input" min="10000" max="10000000" step="1000">
                            <span class="field-hint">{{ __('Default ₹2,00,000 per Income Tax Rule 114B.') }}</span>
                        </div>
                    </div>
                    <div class="form-row cols-3">
                        <div class="field">
                            <label class="field-label">{{ __('PAN Mandatory') }}</label>
                            <select name="compliance_pan_mandatory" class="field-input">
                                <option value="1" {{ ($preferences->compliance_pan_mandatory ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !($preferences->compliance_pan_mandatory ?? true) ? 'selected' : '' }}>{{ __('No') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Mobile Mandatory') }}</label>
                            <select name="compliance_mobile_mandatory" class="field-input">
                                <option value="1" {{ ($preferences->compliance_mobile_mandatory ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !($preferences->compliance_mobile_mandatory ?? true) ? 'selected' : '' }}>{{ __('No') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Address Mandatory') }}</label>
                            <select name="compliance_address_mandatory" class="field-input">
                                <option value="1" {{ ($preferences->compliance_address_mandatory ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !($preferences->compliance_address_mandatory ?? true) ? 'selected' : '' }}>{{ __('No') }}</option>
                            </select>
                        </div>
                    </div>

                    @if($canEditSettings)
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Changes') }}</button>
                    </div>
                    @endif
                </form>
            @endif

            @if($activeTab === 'return-policy')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Return Policy') }}</h2>
                    <p class="settings-desc">{{ __('Controls how much is refunded on returns and exchanges. These rules are applied at return time and recorded on every credit note.') }}</p>
                </div>

                @unless($preferences->hasConfiguredReturnPolicy())
                <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span class="font-semibold">{{ __('Not configured yet.') }}</span>
                    {{ __('Until you save this once, returns default to refunding 100% of everything. Review and save to activate your policy.') }}
                </div>
                @endunless

                <form method="POST" action="{{ route('settings.update.return-policy') }}" data-turbo-frame="_top">
                    @csrf
                    @method('PATCH')

                    <div class="section-label">{{ __('What is refunded') }}</div>
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Refund metal/gold value') }}</label>
                            <select class="field-input" disabled>
                                <option selected>{{ __('Always refunded') }}</option>
                            </select>
                            <span class="field-hint">{{ __('Metal value is always refunded.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Refund making charges') }}</label>
                            <select name="refund_making_charges" class="field-input">
                                <option value="1" {{ (bool)($preferences->refund_making_charges ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !(bool)($preferences->refund_making_charges ?? true) ? 'selected' : '' }}>{{ __('No (retain)') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Refund stone charges') }}</label>
                            <select name="refund_stone_charges" class="field-input">
                                <option value="1" {{ (bool)($preferences->refund_stone_charges ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !(bool)($preferences->refund_stone_charges ?? true) ? 'selected' : '' }}>{{ __('No (retain)') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Refund hallmark charges') }}</label>
                            <select name="refund_hallmark_charges" class="field-input">
                                <option value="1" {{ (bool)($preferences->refund_hallmark_charges ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !(bool)($preferences->refund_hallmark_charges ?? true) ? 'selected' : '' }}>{{ __('No (retain)') }}</option>
                            </select>
                            <span class="field-hint">{{ __('Applies to items sold with a separate hallmark charge. Older bills where hallmark was not itemised are unaffected.') }}</span>
                        </div>
                    </div>
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Refund GST') }}</label>
                            <select name="refund_gst" class="field-input">
                                <option value="1" {{ (bool)($preferences->refund_gst ?? true) ? 'selected' : '' }}>{{ __('Yes') }}</option>
                                <option value="0" {{ !(bool)($preferences->refund_gst ?? true) ? 'selected' : '' }}>{{ __('No (retain)') }}</option>
                            </select>
                            <span class="field-hint">{{ __('GST is reversed proportionally on the refunded amount when Yes.') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Deductions') }}</div>
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Wear / handling loss (%)') }}</label>
                            <input type="number" name="wear_loss_pct" value="{{ old('wear_loss_pct', $preferences->wear_loss_pct ?? 0) }}" class="field-input" min="0" max="25" step="0.01">
                            <span class="field-hint">{{ __('Flat % deducted from the refundable value. 0 = none.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Restocking fee (%)') }}</label>
                            <input type="number" name="restocking_fee_pct" value="{{ old('restocking_fee_pct', $preferences->restocking_fee_pct ?? 0) }}" class="field-input" min="0" max="25" step="0.01">
                            <span class="field-hint">{{ __('Retained by the shop on top of other deductions. 0 = none.') }}</span>
                        </div>
                    </div>

                    <div class="section-divider"></div>
                    <div class="section-label">{{ __('Windows & settlement') }}</div>
                    <div class="form-row cols-4">
                        <div class="field">
                            <label class="field-label">{{ __('Return window (days)') }}</label>
                            <input type="number" name="return_window_days" value="{{ old('return_window_days', $preferences->return_window_days ?? 0) }}" class="field-input" min="0" max="3650">
                            <span class="field-hint">{{ __('0 = no limit. Returns past this need manager approval.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Exchange window (days)') }}</label>
                            <input type="number" name="exchange_window_days" value="{{ old('exchange_window_days', $preferences->exchange_window_days ?? 0) }}" class="field-input" min="0" max="3650">
                            <span class="field-hint">{{ __('0 = no limit.') }}</span>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Refund settlement') }}</label>
                            <select name="return_settlement_mode" class="field-input">
                                <option value="cash_or_credit"     {{ ($preferences->return_settlement_mode ?? 'cash_or_credit') === 'cash_or_credit'     ? 'selected' : '' }}>{{ __('Cash or store credit') }}</option>
                                <option value="cash_only"          {{ ($preferences->return_settlement_mode ?? 'cash_or_credit') === 'cash_only'          ? 'selected' : '' }}>{{ __('Cash only') }}</option>
                                <option value="store_credit_only"  {{ ($preferences->return_settlement_mode ?? 'cash_or_credit') === 'store_credit_only'  ? 'selected' : '' }}>{{ __('Store credit only') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label">{{ __('Lock exchange gold rate basis') }}</label>
                            <select name="exchange_rate_basis_locked" class="field-input">
                                <option value="0" {{ !(bool)($preferences->exchange_rate_basis_locked ?? false) ? 'selected' : '' }}>{{ __('No — staff can choose') }}</option>
                                <option value="1" {{ (bool)($preferences->exchange_rate_basis_locked ?? false) ? 'selected' : '' }}>{{ __('Yes — lock to shop default') }}</option>
                            </select>
                        </div>
                    </div>

                    @if($canEditSettings)
                    <div class="form-footer">
                        <button type="submit" class="btn-primary">{{ __('Save Return Policy') }}</button>
                    </div>
                    @endif
                </form>
            @endif

            @if($activeTab === 'pricing' && $shop->isRetailer())
                @include('partials.settings.pricing-tab')
            @endif

            @if($activeTab === 'materials')
                @php
                    $metalDescriptions = [
                        'platinum' => 'Sold at a fixed price per piece. Turn on only if you keep platinum items.',
                        'copper'   => 'For special items like pooja or copper articles. Most shops keep this off.',
                    ];
                @endphp
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Materials') }}</h2>
                    <p class="settings-desc">{{ __('Choose which metals your shop sells. Gold and silver are always on.') }}</p>
                </div>

                <form method="POST" action="{{ route('settings.update.materials') }}" data-turbo-frame="_top" class="space-y-6 max-w-2xl">
                    @csrf
                    @method('PATCH')

                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">{{ __('Main metals') }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ __('Always available — these are the heart of your shop.') }}</p>
                        </div>
                        <ul class="divide-y divide-slate-100">
                            @foreach($materialsData['primary'] as $metal)
                                <li class="flex items-center justify-between px-5 py-3">
                                    <span class="text-sm font-medium text-slate-800 capitalize">{{ $metal }}</span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                        {{ __('On') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">{{ __('Other metals') }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ __('Turn on only if you actually sell them.') }}</p>
                        </div>
                        <ul class="divide-y divide-slate-100">
                            @foreach($materialsData['tier2'] as $row)
                                <li class="flex items-start justify-between gap-4 px-5 py-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800 capitalize">{{ $row['metal'] }}</p>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $metalDescriptions[$row['metal']] ?? '' }}</p>
                                    </div>
                                    <label class="settings-toggle-label settings-toggle-label-no-margin flex-shrink-0">
                                        <input type="hidden" name="metals[{{ $row['metal'] }}]" value="0">
                                        <input type="checkbox" name="metals[{{ $row['metal'] }}]" value="1"
                                               class="settings-toggle-input-lg"
                                               @checked($row['enabled']) @cannot('settings.edit') disabled @endcannot>
                                        <span class="settings-toggle-text">{{ __('Sell this metal') }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white px-5 py-4">
                        <h3 class="text-sm font-semibold text-slate-900">{{ __('Stones') }}</h3>
                        <p class="text-xs text-slate-500 mt-1">{{ __('Stones are added as a rupee amount on each item. Nothing to set up here.') }}</p>
                    </section>

                    @can('settings.edit')
                        <div>
                            <button type="submit" class="inline-flex items-center rounded-lg bg-amber-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-amber-600 transition">
                                {{ __('Save materials') }}
                            </button>
                        </div>
                    @endcan
                </form>

                {{-- Class-B reference prices: an optional memo per enabled Tier-2 metal.
                     Display hint only — never an accounting rate. See pricing-control-plan.md. --}}
                @php
                    $enabledTier2 = array_values(array_filter(($materialsData['tier2'] ?? []), fn ($r) => $r['enabled']));
                @endphp
                @if(! empty($enabledTier2))
                    <div class="mt-6 max-w-2xl space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">{{ __('Reference prices') }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ __('Optional note — what you are selling these metals at this week. Used as a hint only; never a daily rate.') }}
                            </p>
                        </div>

                        @foreach($enabledTier2 as $row)
                            @php
                                $metal  = $row['metal'];
                                $latest = $row['latest_reference'] ?? null;
                            @endphp

                            <section class="rounded-xl border border-slate-200 bg-white">
                                <div class="border-b border-slate-100 px-5 py-3">
                                    <h4 class="text-sm font-semibold text-slate-900 capitalize">{{ $metal }} {{ __('reference price') }}</h4>
                                    @if($latest)
                                        <p class="text-xs text-slate-600 mt-1">
                                            {{ __('Last noted') }}:
                                            <strong>₹{{ number_format((float) $latest->reference_price, 2) }} / g</strong>
                                            · {{ optional($latest->noted_at)->format('d M Y') }}
                                            @if($latest->notedBy)
                                                {{ __('by') }} {{ $latest->notedBy->name ?? __('user') }}
                                            @endif
                                            @if(filled($latest->note))
                                                — <span class="italic">"{{ $latest->note }}"</span>
                                            @endif
                                        </p>
                                    @else
                                        <p class="text-xs text-slate-500 mt-1">{{ __('No reference noted yet — fine to leave blank.') }}</p>
                                    @endif
                                </div>

                                @can('settings.edit')
                                    <form method="POST" action="{{ route('settings.update.material-reference') }}" data-turbo-frame="_top" class="px-5 py-4 space-y-3">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="metal_type" value="{{ $metal }}">
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Price per gram (₹)') }}</label>
                                                <input type="number" name="reference_price" step="0.01" min="0" required
                                                       class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-40">
                                            </div>
                                            <div class="flex-1 min-w-[180px]">
                                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Note (optional)') }}</label>
                                                <input type="text" name="note" maxlength="255"
                                                       placeholder="{{ __('e.g. supplier price this week') }}"
                                                       class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-full">
                                            </div>
                                            <button type="submit"
                                                    class="inline-flex items-center rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600 transition">
                                                {{ __('Note new price') }}
                                            </button>
                                        </div>
                                    </form>
                                @endcan

                                <div class="border-t border-slate-100 bg-slate-50 px-5 py-2">
                                    <p class="text-[11px] text-slate-500">
                                        {{ __('Used as a hint only; this metal is sold at a fixed price per piece.') }}
                                    </p>
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endif
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
                                <h4 class="role-title">{{ __($role->display_name) }}</h4>
                                <span class="role-badge">{{ $role->permissions->count() }} {{ __('permissions') }}</span>
                                @if($role->name === 'owner')
                                    <span class="locked-msg role-badge-spacer">{{ __('All permissions — locked') }}</span>
                                @endif
                            </div>

                            @if($role->name !== 'owner')
                                <form method="POST" action="{{ route('settings.update.role', $role) }}" data-turbo-frame="_top">
                                    @csrf
                                    @method('PATCH')

                                    <div class="role-body">
                                        @foreach($permissionGroups as $group => $groupPerms)
                                            {{-- Dhiran is a separate product (own subdomain); never surface its
                                                 permission group in the JewelFlow Roles UI. The DB group key is
                                                 'dhiran' (this guard previously checked a label that never matched). --}}
                                            @if($group === 'dhiran') @continue @endif
                                            <div class="perm-group">
                                                <div class="perm-group-title">{{ __($group) }}</div>
                                                @foreach($groupPerms as $perm)
                                                    <label class="perm-item">
                                                        <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                                            class="settings-toggle-input-md"
                                                            {{ $role->permissions->contains($perm->id) ? 'checked' : '' }}>
                                                        <span class="perm-item-label">{{ __($perm->display_name) }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="role-foot">
                                        <button type="submit" class="role-save-btn">{{ __('Save') }} {{ __($role->display_name) }}</button>
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
                    <p class="settings-desc">{{ __('Manage your shop\'s employees and their access') }}</p>
                </div>

                @php
                    $atLimit  = $staffLimit !== -1 && $staffCount >= $staffLimit;
                    $pct      = $staffLimit > 0 ? min(100, round($staffCount / $staffLimit * 100)) : 0;
                    $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0d9488');
                @endphp

                {{-- Limit bar --}}
                <div class="mb-4 flex items-center gap-4 p-3 bg-white border border-gray-200 rounded-lg text-sm">
                    <span class="text-gray-600 font-medium whitespace-nowrap">{{ __('Staff accounts:') }}</span>
                    @if($staffLimit === -1)
                        <span class="text-gray-700">{{ $staffCount }} {{ __('used') }} &nbsp;<span class="text-green-600 font-semibold">· {{ __('Unlimited') }}</span></span>
                    @else
                        <div class="flex-1 flex items-center gap-3">
                            <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div style="width:{{ $pct }}%; background:{{ $barColor }};" class="h-full rounded-full transition-all"></div>
                            </div>
                            <span class="whitespace-nowrap font-semibold {{ $atLimit ? 'text-red-600' : 'text-gray-700' }}">
                                {{ $staffCount }} / {{ $staffLimit }}
                                @if($atLimit) — {{ __('Limit reached') }} @endif
                            </span>
                        </div>
                    @endif
                    @can('staff.manage')
                    <div class="ml-auto">
                        @if($atLimit)
                            <span class="btn btn-secondary btn-sm opacity-50 cursor-not-allowed">+ {{ __('Add Staff') }}</span>
                        @else
                            <a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top">+ {{ __('Add Staff') }}</a>
                        @endif
                    </div>
                    @endcan
                </div>

                {{-- Staff cards --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($staff as $member)
                        <div class="bg-white rounded-xl border border-gray-200 p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-full bg-teal-100 flex items-center justify-center text-teal-700 font-bold">
                                        {{ strtoupper(substr($member->name ?? $member->mobile_number, 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900 text-sm">{{ $member->name ?? $member->mobile_number }}</p>
                                        @if($member->name)
                                            <p class="text-xs text-gray-500">{{ $member->mobile_number }}</p>
                                        @endif
                                        @if($member->email)
                                            <p class="text-xs text-gray-400">{{ $member->email }}</p>
                                        @endif
                                    </div>
                                </div>
                                @php
                                    $roleName = $member->role?->name ?? 'staff';
                                    $roleColors = ['owner' => 'bg-purple-100 text-purple-800', 'manager' => 'bg-blue-100 text-blue-800', 'staff' => 'bg-gray-100 text-gray-700'];
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $roleColors[$roleName] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ $member->role?->display_name ?? 'Staff' }}
                                </span>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                                @if(($member->employment_status ?? 'active') === 'terminated')
                                    <span class="text-xs text-rose-500">{{ __('Removed') }} {{ optional($member->terminated_at)->format('d M Y') }}</span>
                                @else
                                    <span class="text-xs text-gray-400">{{ __('Joined') }} {{ $member->created_at->format('d M Y') }}</span>
                                @endif
                                @php
                                    $isOwnerRow = $member->role?->name === 'owner';
                                    $isSelfRow  = $member->id === auth()->id();
                                    $isTerminated = ($member->employment_status ?? 'active') === 'terminated';
                                @endphp
                                @if($isSelfRow)
                                    <span class="text-xs text-gray-400 italic">{{ __('You') }}</span>
                                @elseif($isOwnerRow)
                                    {{-- The owner account is managed only via Profile, never from staff management. --}}
                                    <span class="text-xs text-gray-400 italic">{{ __('Owner') }}</span>
                                @else
                                    {{-- Edit / Remove / Recover require staff.manage; the owner-only default
                                         means a view-only manager sees no action buttons here. --}}
                                    @can('staff.manage')
                                        @if($isTerminated)
                                            {{-- Recovery: restore a previously-removed staff member. --}}
                                            <form method="POST" action="{{ route('staff.reactivate', $member) }}" data-turbo-frame="_top"
                                                  data-confirm-message="{{ __('Recover :name?', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                @csrf @method('PATCH')
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-emerald-100 text-emerald-700 rounded hover:bg-emerald-200">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.4 2.6L3 8"/><path d="M3 3v5h5"/></svg>
                                                    {{ __('Recover') }}
                                                </button>
                                            </form>
                                        @else
                                            <div class="flex gap-2">
                                                <a href="{{ route('staff.edit', $member) }}" data-turbo-frame="_top"
                                                   class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                    {{ __('Edit') }}
                                                </a>
                                                <form method="POST" action="{{ route('staff.destroy', $member) }}" data-turbo-frame="_top"
                                                      data-confirm-message="{{ __('Remove :name? They can be recovered later.', ['name' => $member->name ?? $member->mobile_number]) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                                        {{ __('Remove') }}
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    @endcan
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="col-span-2 text-center py-10 text-gray-400">
                            <p class="mb-3">{{ __('No staff members yet') }}</p>
                            @if(!$atLimit)
                                <a href="{{ route('staff.create') }}" class="btn btn-primary btn-sm" data-turbo-frame="_top">+ {{ __('Add Staff') }}</a>
                            @endif
                        </div>
                    @endforelse
                </div>
            @endif

            @if($activeTab === 'audit')
                <style>
                    /* ── Audit activity timeline ─────────────────────────────────────
                       A grouped feed, not a table. Day sections, a connecting rail,
                       category glyphs, plain-English lead lines. */
                    /* ── Fixed header/toolbar, scrolling feed ────────────────────────
                       The audit tab fills the settings panel height; the title,
                       summary and filter toolbar stay put while only the event feed
                       scrolls. We neutralise the panel's own scroll for this tab so
                       there is a single, predictable scroll region (the feed). */
                    .content-inner.settings-shell .settings-content:has(.audit-shell) {
                        overflow: hidden;            /* the feed owns scrolling, not the panel */
                        display: flex; flex-direction: column;
                    }
                    .audit-shell {
                        display: flex; flex-direction: column;
                        flex: 1 1 auto; min-height: 0;   /* min-height:0 lets the inner scroller shrink */
                    }
                    .audit-shell-fixed { flex: 0 0 auto; }
                    .audit-scroll {
                        flex: 1 1 auto; min-height: 0;
                        overflow-y: auto; overflow-x: hidden;
                        /* a little room so the last row never kisses the panel edge */
                        padding-bottom: 8px;
                        scrollbar-gutter: stable;
                    }
                    /* Fixed pagination footer: stays put while the feed scrolls above it. */
                    .audit-shell-foot {
                        flex: 0 0 auto;
                        padding-top: 12px; margin-top: 4px;
                        border-top: 1px solid #eef1f5;
                    }
                    /* Fallback for engines without :has() — the feed still scrolls,
                       the page just also scrolls a little; acceptable degradation. */
                    @supports not (selector(:has(*))) {
                        .audit-scroll { max-height: 62vh; }
                    }

                    /* ── Filter toolbar ──────────────────────────────────────────────
                       A calm inline toolbar, not a bordered card. Inputs share one
                       quiet style; the primary action carries the app's teal accent,
                       secondary actions stay ghost. Wraps gracefully, stacks on mobile. */
                    .audit-toolbar {
                        display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 14px;
                        padding-bottom: 16px; margin-bottom: 8px;
                        border-bottom: 1px solid #eef1f5;
                    }
                    .audit-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
                    .audit-field > label { font-size: 11px; font-weight: 600; letter-spacing: .02em; color: #94a3b8; text-transform: uppercase; }
                    .audit-control {
                        height: 38px; border: 1px solid #e2e8f0; border-radius: 10px;
                        background: #fff; padding: 0 12px; font-size: 13.5px; color: #1e293b;
                        transition: border-color 150ms ease, box-shadow 150ms ease;
                    }
                    .audit-control:hover { border-color: #cbd5e1; }
                    .audit-control:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
                    select.audit-control { padding-right: 30px; cursor: pointer; }
                    .audit-field--action select.audit-control { min-width: 160px; }
                    .audit-field--user select.audit-control { min-width: 150px; }
                    .audit-control--date { min-width: 150px; }

                    .audit-toolbar-actions { display: flex; align-items: center; gap: 8px; }
                    .audit-toolbar-spacer { flex: 1 1 auto; }
                    .audit-btn {
                        height: 38px; display: inline-flex; align-items: center; gap: 6px;
                        padding: 0 16px; border-radius: 10px; font-size: 13px; font-weight: 600;
                        cursor: pointer; white-space: nowrap;
                        transition: transform 150ms cubic-bezier(.23,1,.32,1), background-color 150ms ease, border-color 150ms ease;
                    }
                    .audit-btn:active { transform: scale(.97); }
                    .audit-btn--primary { background: #0f766e; color: #fff; border: 1px solid #0f766e; }
                    .audit-btn--primary:hover { background: #0b5f5d; }
                    .audit-btn--ghost { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
                    .audit-btn--ghost:hover { background: #f8fafc; border-color: #cbd5e1; }
                    .audit-btn--ghost svg { width: 16px; height: 16px; }
                    @media (max-width: 640px) {
                        .audit-field, .audit-field--action select.audit-control, .audit-field--user select.audit-control, .audit-control--date { width: 100%; min-width: 0; }
                        .audit-field { flex: 1 1 100%; }
                        .audit-toolbar { gap: 12px; }
                        .audit-toolbar-actions { width: 100%; }
                        .audit-toolbar-actions .audit-btn { flex: 1; justify-content: center; }
                        .audit-toolbar-spacer { display: none; }
                        /* Export: full-width on its own line, consistent with the stack. */
                        .audit-toolbar > a.audit-btn { width: 100%; justify-content: center; }
                    }

                    .audit-day { margin-top: 26px; }
                    .audit-day:first-of-type { margin-top: 4px; }
                    /* Day header: a solid label sitting on a hairline that runs across
                       the row. Not sticky — a nested scroll container makes sticky
                       headers overlap their own rows, so days are separated by clear
                       spacing + a divider instead. */
                    .audit-day-head {
                        display: flex; align-items: baseline; gap: 10px;
                        padding: 0 2px 10px; margin-bottom: 6px;
                        border-bottom: 1px solid #eef1f5;
                    }
                    .audit-day-title { font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #334155; margin: 0; }
                    .audit-day-count { font-size: 12px; color: #94a3b8; }

                    .audit-feed { list-style: none; margin: 0; padding: 0; }

                    .audit-item {
                        position: relative;
                        display: grid;
                        grid-template-columns: 36px 1fr auto;
                        align-items: start;
                        gap: 14px;
                        padding: 12px 12px 12px 4px;
                        border-radius: 14px;
                        transition: background-color 160ms ease;
                    }
                    .audit-item:hover { background: #f8fafc; }
                    .audit-item.is-sensitive { background: rgba(255, 241, 242, .5); }
                    .audit-item.is-sensitive:hover { background: rgba(255, 228, 230, .6); }

                    /* Vertical rail joining the glyphs down a day. Drawn from each
                       glyph centre to the next item; hidden on the last item. */
                    .audit-rail {
                        position: absolute;
                        left: 21px; top: 38px; bottom: -12px; width: 2px;
                        background: #e7ebf0;
                    }
                    .audit-item:last-child .audit-rail { display: none; }
                    .audit-item.is-sensitive .audit-rail { background: #fecdd3; }

                    .audit-glyph {
                        position: relative; z-index: 1;
                        width: 36px; height: 36px; border-radius: 11px;
                        display: flex; align-items: center; justify-content: center;
                        flex-shrink: 0;
                    }
                    .audit-glyph svg { width: 18px; height: 18px; }

                    .audit-body { min-width: 0; padding-top: 1px; }
                    .audit-summary {
                        margin: 0; font-size: 14px; line-height: 1.4; color: #0f172a; font-weight: 500;
                        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
                    }
                    .audit-item.is-sensitive .audit-summary { font-weight: 600; }
                    .audit-flag {
                        font-size: 10.5px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase;
                        color: #be123c; background: #ffe4e6; border-radius: 9999px; padding: 2px 8px; white-space: nowrap;
                    }
                    .audit-meta { margin: 3px 0 0; font-size: 12.5px; color: #64748b; }
                    .audit-meta-cat { color: #475569; font-weight: 600; }
                    .audit-meta-entity { color: #94a3b8; }
                    .audit-meta-sep { color: #cbd5e1; margin: 0 6px; }

                    .audit-detail-btn {
                        align-self: center; flex-shrink: 0;
                        font-size: 12px; font-weight: 600; color: #475569;
                        background: #fff; border: 1px solid #e2e8f0; border-radius: 9px;
                        padding: 5px 11px; cursor: pointer;
                        transition: transform 150ms cubic-bezier(.23,1,.32,1), background-color 150ms ease, border-color 150ms ease;
                    }
                    .audit-detail-btn:hover { background: #f8fafc; border-color: #cbd5e1; }
                    .audit-detail-btn:active { transform: scale(.97); }

                    /* Details modal — receipt-style label/value rows. */
                    .audit-detail-rows { margin: 0; }
                    .audit-detail-row {
                        display: flex; align-items: baseline; justify-content: space-between; gap: 16px;
                        padding: 10px 0; border-bottom: 1px solid #f1f5f9;
                    }
                    .audit-detail-row:last-child { border-bottom: 0; }
                    .audit-detail-row dt { font-size: 13px; color: #64748b; flex-shrink: 0; }
                    .audit-detail-row dd { font-size: 13.5px; color: #0f172a; font-weight: 600; margin: 0; text-align: right; word-break: break-word; }

                    .audit-empty { text-align: center; padding: 56px 16px; }
                    .audit-empty-glyph {
                        width: 52px; height: 52px; border-radius: 14px; margin: 0 auto 14px;
                        display: flex; align-items: center; justify-content: center;
                        background: #f1f5f9; color: #94a3b8;
                    }
                    .audit-empty-glyph svg { width: 26px; height: 26px; }
                    .audit-empty-title { font-size: 15px; font-weight: 600; color: #334155; margin: 0; }
                    .audit-empty-sub { font-size: 13px; color: #94a3b8; margin: 4px 0 0; }

                    /* ── Motion (emil-design-eng): items rise + fade in, staggered
                       top-down so the eye lands on the newest first. Items are
                       visible by default; the keyframe carries its own from-state so
                       nothing is ever stuck invisible if motion never runs. --- */
                    @media (prefers-reduced-motion: no-preference) {
                        .audit-item {
                            animation: auditItemIn 300ms cubic-bezier(.23,1,.32,1) both;
                            animation-delay: calc(var(--audit-i, 0) * 26ms);
                        }
                        @keyframes auditItemIn {
                            from { opacity: 0; transform: translateY(7px); }
                            to   { opacity: 1; transform: translateY(0); }
                        }
                    }

                    /* Details modal: scale-in from near (never from nothing); modals
                       stay centered (the one transform-origin exception). */
                    #jsonModal .audit-modal-panel {
                        transform: scale(.96); opacity: 0;
                        transition: transform 180ms cubic-bezier(.23,1,.32,1), opacity 180ms ease-out;
                    }
                    #jsonModal.is-open .audit-modal-panel { transform: scale(1); opacity: 1; }
                    @media (prefers-reduced-motion: reduce) {
                        #jsonModal .audit-modal-panel { transition: opacity 120ms ease; transform: none; }
                    }

                    @media (max-width: 640px) {
                        .audit-item { grid-template-columns: 32px 1fr; gap: 11px; }
                        .audit-detail-btn { grid-column: 2; justify-self: start; margin-top: 6px; }
                        .audit-rail { left: 19px; }
                    }
                </style>
                <div class="audit-shell">
                <div class="audit-shell-fixed">
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Audit Log') }}</h2>
                    <p class="settings-desc">{{ __('Track all system activities and changes') }}</p>
                </div>

                @php $sensitiveToday = $stats['sensitive'] ?? 0; $activityToday = $stats['today'] ?? 0; @endphp
                {{-- A calm one-line summary, not three boxes. The attention count is the
                     only thing that ever raises its voice, and only when it is non-zero. --}}
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 mb-5 text-sm text-gray-600">
                    <span class="text-gray-900 font-semibold">{{ number_format($activityToday) }}</span>
                    <span>{{ trans_choice('{0,1}action today|[2,*]actions today', $activityToday) }}.</span>
                    @if($sensitiveToday > 0)
                        <span class="inline-flex items-center gap-1.5 text-rose-700 font-medium">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                            {{ trans_choice('{1}:count needs your attention|[2,*]:count need your attention', $sensitiveToday, ['count' => $sensitiveToday]) }}
                        </span>
                    @else
                        <span class="text-gray-400">·</span>
                        <span class="text-gray-500">{{ __('nothing flagged') }}</span>
                    @endif
                    <span class="text-gray-300 hidden sm:inline">·</span>
                    <span class="text-gray-400 hidden sm:inline">{{ number_format($stats['total'] ?? $logs->total()) }} {{ __('total recorded') }}</span>
                </div>

                {{-- Filters: action / user / date range. GET form targets the same
                     tab via the settings-content turbo frame; withQueryString()
                     on the paginator keeps filters across page changes. --}}
                @php
                    $hasAuditFilter = request()->hasAny(['audit_action', 'audit_user', 'audit_from', 'audit_to']);
                    $auditExportParams = array_filter([
                        'audit_action' => request('audit_action'),
                        'audit_user'   => request('audit_user'),
                        'audit_from'   => request('audit_from'),
                        'audit_to'     => request('audit_to'),
                    ]);
                @endphp
                <form method="GET" action="{{ route('settings.edit', ['tab' => 'audit']) }}"
                      data-turbo-frame="settings-content" class="audit-toolbar">
                    <input type="hidden" name="tab" value="audit">

                    <div class="audit-field audit-field--action">
                        <label for="audit_action">{{ __('Action') }}</label>
                        <select id="audit_action" name="audit_action" class="audit-control">
                            <option value="">{{ __('All actions') }}</option>
                            @foreach($auditActions ?? [] as $a)
                                <option value="{{ $a }}" @selected(request('audit_action') === $a)>{{ \Illuminate\Support\Str::headline($a) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="audit-field audit-field--user">
                        <label for="audit_user">{{ __('Who') }}</label>
                        <select id="audit_user" name="audit_user" class="audit-control">
                            <option value="">{{ __('Everyone') }}</option>
                            @foreach($auditUsers ?? [] as $u)
                                <option value="{{ $u->id }}" @selected((string) request('audit_user') === (string) $u->id)>{{ $u->name ?? $u->mobile_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="audit-field">
                        <label for="audit_from">{{ __('From') }}</label>
                        <input id="audit_from" type="date" name="audit_from" value="{{ request('audit_from') }}" class="audit-control audit-control--date">
                    </div>
                    <div class="audit-field">
                        <label for="audit_to">{{ __('To') }}</label>
                        <input id="audit_to" type="date" name="audit_to" value="{{ request('audit_to') }}" class="audit-control audit-control--date">
                    </div>

                    <div class="audit-toolbar-actions">
                        <button type="submit" class="audit-btn audit-btn--primary">{{ __('Apply') }}</button>
                        @if($hasAuditFilter)
                            <a href="{{ route('settings.edit', ['tab' => 'audit']) }}" data-turbo-frame="settings-content" class="audit-btn audit-btn--ghost">{{ __('Clear') }}</a>
                        @endif
                    </div>

                    <span class="audit-toolbar-spacer"></span>

                    {{-- Download leaves the turbo frame (streams a file). Carries active filters. --}}
                    <a href="{{ route('settings.audit.export', $auditExportParams) }}" data-turbo-frame="_top" class="audit-btn audit-btn--ghost">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        {{ __('Export') }}
                    </a>
                </form>
                </div>{{-- /.audit-shell-fixed --}}

                @php
                    // Group the page's events by calendar day for the timeline headers.
                    $auditGroups = $logs->getCollection()->groupBy(fn ($l) => optional($l->created_at)->toDateString() ?? 'unknown');
                    $dayLabel = function ($dateKey) {
                        if ($dateKey === 'unknown') return __('Earlier');
                        $d = \Carbon\Carbon::parse($dateKey);
                        if ($d->isToday()) return __('Today');
                        if ($d->isYesterday()) return __('Yesterday');
                        return $d->isCurrentYear() ? $d->format('D, d M') : $d->format('d M Y');
                    };
                    $rowIndex = 0;
                @endphp

                <div class="audit-scroll">
                @forelse($auditGroups as $dateKey => $dayLogs)
                    <section class="audit-day">
                        <div class="audit-day-head">
                            <h3 class="audit-day-title">{{ $dayLabel($dateKey) }}</h3>
                            <span class="audit-day-count">{{ trans_choice('{1}:count event|[2,*]:count events', $dayLogs->count(), ['count' => $dayLogs->count()]) }}</span>
                        </div>

                        <ol class="audit-feed">
                            @foreach($dayLogs as $l)
                                @php
                                    $summary   = $l->summaryLine();
                                    $sensitive = $l->isSensitive();
                                    $cat       = $l->category();
                                    $who       = $l->user->name ?? $l->user->mobile_number ?? __('System');
                                    $isSystem  = $l->user === null;
                                    $entityType = $l->model_type ? \Illuminate\Support\Str::headline($l->model_type) : null;
                                    $entityLabel = ($entityType && $l->model_id) ? ($entityType . ' #' . $l->model_id) : ($entityType ?: null);

                                    // Plain-English rows for the owner; raw JSON kept only as a
                                    // "technical" fallback for a CA / support. A row gets a Details
                                    // button only when there is more to show than the summary line.
                                    $readable = $l->readableDetails();
                                    $rawJson = (is_array($l->data) && $l->data !== [])
                                        ? json_encode((array) $l->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                        : null;
                                    $hasDetails = ! empty($readable) || ($rawJson !== null);
                                    $detailBundle = $hasDetails
                                        ? base64_encode(json_encode([
                                            'title'    => $summary,
                                            'meta'     => trim(($cat['label'] ?? '') . ' · ' . ($isSystem ? __('System') : $who) . ' · ' . optional($l->created_at)->format('d M Y, h:i A')),
                                            'readable' => $readable,
                                            'raw'      => $rawJson,
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                                        : null;
                                    $i = $rowIndex < 24 ? $rowIndex : 24; $rowIndex++;
                                @endphp
                                <li class="audit-item {{ $sensitive ? 'is-sensitive' : '' }}" style="--audit-i: {{ $i }}">
                                    <span class="audit-rail" aria-hidden="true"></span>
                                    <span class="audit-glyph {{ $cat['bg'] }} {{ $cat['fg'] }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="{{ $cat['icon'] }}" />
                                        </svg>
                                    </span>
                                    <div class="audit-body">
                                        <p class="audit-summary">
                                            {{ $summary }}
                                            @if($sensitive)<span class="audit-flag">{{ __('Needs attention') }}</span>@endif
                                        </p>
                                        <p class="audit-meta">
                                            <span class="audit-meta-cat">{{ $cat['label'] }}</span>
                                            <span class="audit-meta-sep">·</span>
                                            {{ $isSystem ? __('System') : $who }}
                                            <span class="audit-meta-sep">·</span>
                                            {{ optional($l->created_at)->format('h:i A') }}
                                            @if($entityLabel)<span class="audit-meta-sep">·</span><span class="audit-meta-entity">{{ $entityLabel }}</span>@endif
                                        </p>
                                    </div>
                                    @if($detailBundle)
                                        <button type="button" class="view-detail audit-detail-btn" data-detail="{{ $detailBundle }}" aria-label="{{ __('View details') }}">
                                            {{ __('Details') }}
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @empty
                    <div class="audit-empty">
                        <div class="audit-empty-glyph">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="audit-empty-title">{{ __('Nothing recorded yet') }}</p>
                        <p class="audit-empty-sub">{{ __('As you and your staff use the shop, every important action shows up here.') }}</p>
                    </div>
                @endforelse
                </div>{{-- /.audit-scroll --}}

                {{-- Pagination is a fixed footer of the shell, not part of the
                     scrolling feed — you reach Next without scrolling to the bottom. --}}
                @if($logs->hasPages())
                    <div class="audit-shell-foot">{{ $logs->links() }}</div>
                @endif
                </div>{{-- /.audit-shell --}}

                <div id="jsonModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 z-50 p-4">
                    <div class="audit-modal-panel bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 max-h-[88vh] flex flex-col overflow-hidden">
                        <div class="flex items-start justify-between gap-4 px-6 pt-5 pb-4 border-b border-gray-100">
                            <div class="min-w-0">
                                <h3 id="auditDetailTitle" class="text-base font-semibold text-gray-900 leading-snug"></h3>
                                <p id="auditDetailMeta" class="text-xs text-gray-500 mt-1"></p>
                            </div>
                            <button id="closeJson" class="flex-shrink-0 -mr-1 -mt-1 p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors" aria-label="{{ __('Close') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                        <div class="flex-1 overflow-auto px-6 py-5">
                            {{-- Plain-English rows: label on the left, value on the right, like a receipt. --}}
                            <dl id="auditDetailRows" class="audit-detail-rows"></dl>
                            <p id="auditDetailEmpty" class="text-sm text-gray-400 hidden">{{ __('No extra details for this action.') }}</p>

                            {{-- Technical details tucked away for a CA / support, not shown by default. --}}
                            <div id="auditDetailRawWrap" class="mt-5 hidden">
                                <button id="auditRawToggle" type="button" class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700">
                                    <svg id="auditRawChevron" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                                    {{ __('Show technical details') }}
                                </button>
                                <div id="auditRawBody" class="hidden mt-3">
                                    <div class="flex justify-end mb-2">
                                        <button id="copyJson" class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                            {{ __('Copy') }}
                                        </button>
                                    </div>
                                    <pre id="jsonContent" class="bg-gray-50 rounded-lg p-4 text-xs font-mono text-gray-700 whitespace-pre-wrap border border-gray-100 max-h-64 overflow-auto"></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    // Decode the base64-wrapped UTF-8 detail bundle safely.
                    function auditDecode(b64) {
                        try {
                            const bin = atob(b64);
                            const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
                            return JSON.parse(new TextDecoder().decode(bytes));
                        } catch (_) { return null; }
                    }

                    function auditCloseModal() {
                        const modal = document.getElementById('jsonModal');
                        modal.classList.remove('is-open');
                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                    }

                    document.addEventListener('click', function(e){
                        const trigger = e.target.closest('.view-detail');
                        if (trigger) {
                            const bundle = auditDecode(trigger.getAttribute('data-detail')) || {};
                            document.getElementById('auditDetailTitle').textContent = bundle.title || 'Details';
                            document.getElementById('auditDetailMeta').textContent = bundle.meta || '';

                            // Build the plain-English rows as DOM nodes (textContent = no XSS).
                            const rowsEl = document.getElementById('auditDetailRows');
                            rowsEl.innerHTML = '';
                            const rows = Array.isArray(bundle.readable) ? bundle.readable : [];
                            rows.forEach(r => {
                                const row = document.createElement('div');
                                row.className = 'audit-detail-row';
                                const dt = document.createElement('dt'); dt.textContent = r.label;
                                const dd = document.createElement('dd'); dd.textContent = r.value;
                                row.append(dt, dd); rowsEl.append(row);
                            });
                            document.getElementById('auditDetailEmpty').classList.toggle('hidden', rows.length > 0);

                            // Technical (raw) section: only offer it when raw JSON exists.
                            const rawWrap = document.getElementById('auditDetailRawWrap');
                            const rawBody = document.getElementById('auditRawBody');
                            const chevron = document.getElementById('auditRawChevron');
                            rawBody.classList.add('hidden');
                            chevron.style.transform = '';
                            if (bundle.raw) {
                                document.getElementById('jsonContent').textContent = bundle.raw;
                                rawWrap.classList.remove('hidden');
                            } else {
                                rawWrap.classList.add('hidden');
                            }

                            const modal = document.getElementById('jsonModal');
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                            requestAnimationFrame(() => modal.classList.add('is-open'));
                        }

                        if (e.target.closest('#auditRawToggle')) {
                            const body = document.getElementById('auditRawBody');
                            const chevron = document.getElementById('auditRawChevron');
                            const open = body.classList.toggle('hidden') === false;
                            chevron.style.transform = open ? 'rotate(90deg)' : '';
                        }

                        if (e.target.closest('#closeJson')) {
                            auditCloseModal();
                        }

                        const copyBtn = e.target.closest('#copyJson');
                        if (copyBtn) {
                            const text = document.getElementById('jsonContent').textContent || '';
                            navigator.clipboard.writeText(text).then(() => {
                                const original = copyBtn.textContent;
                                copyBtn.textContent = '{{ __('Copied!') }}';
                                setTimeout(() => { copyBtn.textContent = original; }, 1500);
                            });
                        }

                        // Backdrop click (outside the panel) closes.
                        if (e.target && e.target.id === 'jsonModal') {
                            auditCloseModal();
                        }
                    });

                    // Escape closes the details modal.
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape') {
                            const modal = document.getElementById('jsonModal');
                            if (modal && !modal.classList.contains('hidden')) {
                                auditCloseModal();
                            }
                        }
                    });
                </script>
            @endif

            @if($activeTab === 'website')
                @include('partials.settings.website-tab')
            @endif

            @if($activeTab === 'services')
                @php
                    $active          = $servicesData['active'];
                    $available       = $servicesData['available'];
                    $pendingRequests = $servicesData['pendingRequests'];
                    $history         = $servicesData['history'];
                    $assignments     = $servicesData['assignments'];
                    $pendingRemove   = $pendingRequests->where('action', 'remove');
                @endphp

                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Business Editions') }}</h2>
                    <p class="settings-desc">{{ __('These are the kinds of business your shop is set up to run. Add a new one or remove one you no longer need.') }}</p>
                </div>

                {{-- Buy-now card polish: press feedback + hover, matched to the teal
                     accent used on the subscription payment page. Kept scoped so it
                     touches nothing outside the services tab. --}}
                <style>
                    .buy-service-btn {
                        transition: background-color .16s ease-out, transform .16s ease-out, box-shadow .16s ease-out;
                    }
                    @media (hover: hover) and (pointer: fine) {
                        .buy-service-btn:hover:not(:disabled) {
                            background-color: #0d665f;
                            box-shadow: 0 6px 16px rgba(15, 118, 110, 0.22);
                        }
                    }
                    .buy-service-btn:active:not(:disabled) { transform: scale(0.98); }
                    .buy-service-btn:disabled { opacity: .65; cursor: not-allowed; }
                    .buy-service-btn:focus-visible {
                        outline: 2px solid #0f766e;
                        outline-offset: 2px;
                    }
                    .buy-cycle-tab { transition: color .16s ease-out, background-color .16s ease-out; }
                    @media (prefers-reduced-motion: reduce) {
                        .buy-service-btn, .buy-cycle-tab { transition: none; }
                        .buy-service-btn:active:not(:disabled) { transform: none; }
                    }
                </style>

                <p class="text-xs text-slate-500 mb-6 max-w-3xl">
                    {{ __('Adding an edition may change your plan.') }}
                    <a href="{{ route('settings.edit', ['tab' => 'subscription']) }}" data-turbo-frame="settings-content" class="font-semibold text-teal-700 hover:text-teal-800 underline">{{ __('See Plan & Billing') }}</a>.
                </p>

                <div class="space-y-8 max-w-3xl">
                    {{-- Active services --}}
                    <section>
                        <h3 class="text-sm font-semibold text-slate-900 mb-3">{{ __('What you have now') }}</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @foreach($active as $ed)
                                @if($ed === 'dhiran') @continue @endif
                                @php
                                    $meta = [
                                        'retailer'     => ['label' => 'Retailer',     'desc' => 'Buy and sell ready-made jewellery.'],
                                        'manufacturer' => ['label' => 'Manufacturer', 'desc' => 'Make jewellery in your own workshop.'],
                                    ][$ed] ?? ['label' => ucfirst($ed), 'desc' => ''];
                                    $assignment = $assignments[$ed] ?? null;
                                @endphp
                                <div class="rounded-xl border border-slate-200 bg-white">
                                    <div class="px-5 py-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold text-slate-900">{{ __($meta['label']) }}</div>
                                                <div class="text-xs text-slate-500 mt-0.5">{{ __($meta['desc']) }}</div>
                                            </div>
                                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-700 flex-shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                {{ __('On') }}
                                            </span>
                                        </div>
                                        @if($assignment?->activated_at)
                                            <div class="text-xs text-slate-400 mt-2">
                                                {{ __('On since') }} {{ $assignment->activated_at->format('d M Y') }}
                                            </div>
                                        @endif
                                    </div>

                                    @can('settings.edit')
                                        @if(count($active) > 1)
                                            <details class="border-t border-slate-100">
                                                <summary class="cursor-pointer list-none px-5 py-3 text-xs font-medium text-rose-600 hover:text-rose-700">
                                                    {{ __('Turn this off') }}
                                                </summary>
                                                <form method="POST" action="{{ route('settings.services.remove') }}" data-turbo-frame="_top" class="px-5 pb-4 space-y-3">
                                                    @csrf
                                                    <input type="hidden" name="edition" value="{{ $ed }}">
                                                    <div>
                                                        <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Why are you turning this off?') }}</label>
                                                        <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:ring-1 focus:ring-teal-600"
                                                                  placeholder="{{ __('e.g. We no longer make jewellery in-house.') }}"></textarea>
                                                    </div>
                                                    <label class="flex items-start gap-2 text-xs text-slate-600">
                                                        <input type="checkbox" name="confirm" value="1" required class="mt-0.5">
                                                        <span>{{ __('I understand this turns off this service for my shop.') }}</span>
                                                    </label>
                                                    <button type="submit" class="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-700 transition">
                                                        {{ __('Turn off') }} {{ __($meta['label']) }}
                                                    </button>
                                                </form>
                                            </details>
                                        @else
                                            <div class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400">
                                                {{ __('This is your only service. To stop using it, please contact support.') }}
                                            </div>
                                        @endif
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                    </section>

                    {{-- Available to add --}}
                    @if(count($available) > 0)
                        @php $purchaseOptions = $servicesData['purchase'] ?? []; @endphp
                        <section>
                            <h3 class="text-sm font-semibold text-slate-900 mb-3">{{ __('Add a new service') }}</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                @foreach($available as $ed)
                                    @php
                                        $meta = [
                                            'retailer'     => ['label' => 'Retailer',     'desc' => 'Buy and sell ready-made jewellery.'],
                                            'manufacturer' => ['label' => 'Manufacturer', 'desc' => 'Make jewellery in your own workshop.'],
                                            'dhiran'       => ['label' => 'Dhiran (Gold Loan)', 'desc' => 'Give gold loans and manage pledged items.'],
                                        ][$ed] ?? ['label' => ucfirst($ed), 'desc' => ''];
                                        $pending = $pendingRequests->firstWhere(fn($r) => $r->edition === $ed && $r->action === 'add');
                                        $buy     = $purchaseOptions[$ed] ?? null;
                                        $canBuy  = $buy && ($buy['purchasable'] ?? false);
                                    @endphp

                                    @if($canBuy)
                                        {{-- Self-serve: owner can buy and switch this on right away. --}}
                                        @php
                                            $hasMonthly  = $buy['monthly_price'] !== null;
                                            $hasYearly   = $buy['yearly_price'] !== null;
                                            $defaultCycle = $hasMonthly ? 'monthly' : 'yearly';
                                            // Plain-English yearly saving vs paying monthly for a year.
                                            $yearlySaving = null;
                                            if ($hasMonthly && $hasYearly) {
                                                $twelveMonths = $buy['monthly_price'] * 12;
                                                if ($twelveMonths > $buy['yearly_price']) {
                                                    $yearlySaving = (int) round((1 - ($buy['yearly_price'] / $twelveMonths)) * 100);
                                                }
                                            }
                                        @endphp
                                        <div class="buy-service-card rounded-xl border border-slate-200 bg-white shadow-sm"
                                             x-data="{ cycle: '{{ $defaultCycle }}' }"
                                             data-product="{{ $buy['product_code'] }}"
                                             data-label="{{ $meta['label'] }}">
                                            <div class="px-5 py-4 border-b border-slate-100">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div class="text-sm font-semibold text-slate-900">{{ __($meta['label']) }}</div>
                                                        <div class="text-xs text-slate-500 mt-0.5">{{ __($meta['desc']) }}</div>
                                                    </div>
                                                    <span class="inline-flex items-center rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-700 flex-shrink-0">
                                                        {{ __('Add now') }}
                                                    </span>
                                                </div>
                                            </div>

                                            @can('settings.edit')
                                                <div class="px-5 py-4 space-y-4">
                                                    {{-- Monthly / Yearly choice. Only shown when both exist. --}}
                                                    @if($hasMonthly && $hasYearly)
                                                        <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-semibold" role="tablist" aria-label="{{ __('How often you pay') }}">
                                                            <button type="button" @click="cycle = 'monthly'"
                                                                    :class="cycle === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
                                                                    class="buy-cycle-tab rounded-md px-3 py-1.5">{{ __('Monthly') }}</button>
                                                            <button type="button" @click="cycle = 'yearly'"
                                                                    :class="cycle === 'yearly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
                                                                    class="buy-cycle-tab rounded-md px-3 py-1.5">{{ __('Yearly') }}</button>
                                                        </div>
                                                    @endif

                                                    {{-- Price. Switches with the chosen cycle. --}}
                                                    <div>
                                                        @if($hasMonthly)
                                                            <div x-show="cycle === 'monthly'">
                                                                <span class="text-2xl font-bold text-slate-900">₹{{ number_format($buy['monthly_price'], 0) }}</span>
                                                                <span class="text-xs text-slate-500">{{ __('per month') }}</span>
                                                            </div>
                                                        @endif
                                                        @if($hasYearly)
                                                            <div x-show="cycle === 'yearly'" @if($hasMonthly) x-cloak @endif>
                                                                <span class="text-2xl font-bold text-slate-900">₹{{ number_format($buy['yearly_price'], 0) }}</span>
                                                                <span class="text-xs text-slate-500">{{ __('per year') }}</span>
                                                                @if($yearlySaving)
                                                                    <div class="mt-1 text-[11px] font-semibold text-teal-700">{{ __('Save :pct% vs paying monthly', ['pct' => $yearlySaving]) }}</div>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>

                                                    <button type="button"
                                                            class="buy-service-btn group relative w-full inline-flex items-center justify-center gap-2 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white"
                                                            :data-cycle="cycle"
                                                            data-default-label="{{ __('Buy & activate now') }}">
                                                        <span class="buy-service-btn-label">{{ __('Buy & activate now') }}</span>
                                                        <svg class="buy-service-btn-spinner hidden h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                        </svg>
                                                    </button>

                                                    <p class="buy-service-error hidden text-xs text-rose-700 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2" role="alert" aria-live="polite"></p>

                                                    <p class="text-[11px] text-slate-400 leading-relaxed">{{ __('Secure payment by Razorpay. Switches on right after you pay.') }}</p>

                                                    {{-- Hidden form: filled in by Razorpay on success, then submitted to
                                                         the callback which redirects to a full page → needs _top. --}}
                                                    <form class="buy-service-form" method="POST" action="{{ route('settings.services.add-callback') }}" data-turbo-frame="_top" style="display:none;">
                                                        @csrf
                                                        <input type="hidden" name="razorpay_payment_id">
                                                        <input type="hidden" name="razorpay_order_id">
                                                        <input type="hidden" name="razorpay_signature">
                                                    </form>
                                                </div>

                                                @if($pending)
                                                    <div class="border-t border-slate-100 px-5 py-3">
                                                        <p class="text-[11px] text-slate-500">
                                                            {{ __('You also asked us to set this up on') }} {{ $pending->created_at->format('d M Y') }}.
                                                            <form method="POST" action="{{ route('settings.services.request.cancel', $pending) }}" data-turbo-frame="_top" class="inline">
                                                                @csrf
                                                                <button type="submit" class="font-medium text-slate-500 hover:text-slate-700 underline">{{ __('Cancel that request') }}</button>
                                                            </form>
                                                        </p>
                                                    </div>
                                                @endif
                                            @else
                                                <div class="px-5 py-4 text-xs text-slate-400">
                                                    {{ __('Ask the shop owner to add this service.') }}
                                                </div>
                                            @endcan
                                        </div>
                                    @else
                                        {{-- Not self-serve: keep the admin-review request form. --}}
                                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50">
                                            <div class="px-5 py-4">
                                                <div class="text-sm font-semibold text-slate-900">{{ __($meta['label']) }}</div>
                                                <div class="text-xs text-slate-500 mt-0.5">{{ __($meta['desc']) }}</div>
                                            </div>

                                            @if($pending)
                                                <div class="border-t border-slate-200 px-5 py-3 space-y-2">
                                                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                                        {{ __('Asked for since') }} {{ $pending->created_at->format('d M Y') }}. {{ __('We will get back to you soon.') }}
                                                    </p>
                                                    @can('settings.edit')
                                                        <form method="POST" action="{{ route('settings.services.request.cancel', $pending) }}" data-turbo-frame="_top">
                                                            @csrf
                                                            <button type="submit" class="text-xs font-medium text-slate-500 hover:text-slate-700 underline">{{ __('Cancel this request') }}</button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            @elsecan('settings.edit')
                                                <details class="border-t border-slate-200">
                                                    <summary class="cursor-pointer list-none px-5 py-3 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                        {{ __('Ask to turn this on') }}
                                                    </summary>
                                                    <form method="POST" action="{{ route('settings.services.request-add') }}" data-turbo-frame="_top" class="px-5 pb-4 space-y-3">
                                                        @csrf
                                                        <input type="hidden" name="edition" value="{{ $ed }}">
                                                        <div>
                                                            <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Why do you want this service?') }}</label>
                                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500"
                                                                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:ring-1 focus:ring-teal-600"
                                                                      placeholder="{{ __('A short reason helps us set it up for you.') }}"></textarea>
                                                        </div>
                                                        <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-xs font-semibold text-white hover:bg-teal-800 transition">
                                                            {{ __('Send request') }}
                                                        </button>
                                                    </form>
                                                </details>
                                            @endcan
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endif

                    {{-- Pending removal requests --}}
                    @if($pendingRemove->isNotEmpty())
                        <section>
                            <h3 class="text-sm font-semibold text-slate-900 mb-3">{{ __('Waiting to be turned off') }}</h3>
                            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                                        <tr>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('Service') }}</th>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('Asked on') }}</th>
                                            <th class="px-5 py-2.5 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach($pendingRemove as $req)
                                            <tr>
                                                <td class="px-5 py-3 font-medium text-slate-800">{{ ucfirst($req->edition) }}</td>
                                                <td class="px-5 py-3 text-slate-600">{{ $req->created_at->format('d M Y, H:i') }}</td>
                                                <td class="px-5 py-3 text-right">
                                                    @can('settings.edit')
                                                        <form method="POST" action="{{ route('settings.services.request.cancel', $req) }}" data-turbo-frame="_top">
                                                            @csrf
                                                            <button type="submit" class="text-xs font-medium text-slate-500 hover:text-slate-700 underline">{{ __('Cancel') }}</button>
                                                        </form>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif

                    {{-- Recent activity --}}
                    @if($history->isNotEmpty())
                        <section>
                            <h3 class="text-sm font-semibold text-slate-900 mb-3">{{ __('Recent activity') }}</h3>
                            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                                        <tr>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('What happened') }}</th>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('Result') }}</th>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('On') }}</th>
                                            <th class="px-5 py-2.5 text-left font-semibold">{{ __('Notes') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach($history as $req)
                                            <tr>
                                                <td class="px-5 py-3 text-slate-800">{{ ucfirst($req->action) }} {{ ucfirst($req->edition) }}</td>
                                                <td class="px-5 py-3">
                                                    @php
                                                        $cls = match($req->status) {
                                                            'approved'  => 'text-green-700 bg-green-50',
                                                            'denied'    => 'text-rose-700 bg-rose-50',
                                                            'cancelled' => 'text-slate-600 bg-slate-100',
                                                            default     => 'text-amber-700 bg-amber-50',
                                                        };
                                                        $statusLabel = match($req->status) {
                                                            'approved'  => __('Done'),
                                                            'denied'    => __('Not done'),
                                                            'cancelled' => __('Cancelled'),
                                                            default     => ucfirst($req->status),
                                                        };
                                                    @endphp
                                                    <span class="text-xs font-medium px-2 py-0.5 rounded {{ $cls }}">{{ $statusLabel }}</span>
                                                </td>
                                                <td class="px-5 py-3 text-slate-600">{{ $req->reviewed_at?->format('d M Y') ?? '—' }}</td>
                                                <td class="px-5 py-3 text-slate-600 text-xs">{{ $req->review_notes ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif
                </div>
            @endif

            @if($activeTab === 'subscription')
                @php $needsPlan = $subscriptionData['needs_plan'] ?? false; @endphp

                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Plan & Billing') }}</h2>
                    <p class="settings-desc">{{ __('Your current plan, renewal date, what is included, and your past bills.') }}</p>
                </div>

                <p class="text-xs text-slate-500 mb-6 max-w-3xl">
                    {{ __('Choose which kinds of business your shop runs in') }}
                    <a href="{{ route('settings.edit', ['tab' => 'services']) }}" data-turbo-frame="settings-content" class="font-semibold text-teal-700 hover:text-teal-800 underline">{{ __('Business Editions') }}</a>.
                </p>

                @if($needsPlan)
                    {{-- No subscription on record — point the owner at the plan picker.
                         We can't redirect mid-tab-render, so we render a calm panel. --}}
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center max-w-2xl">
                        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4Z"/><path d="M4 6v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/><path d="M12 12v4h4"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-700">{{ __('No active plan yet') }}</p>
                        <p class="mt-1 text-sm text-slate-500 max-w-md mx-auto">{{ __('Choose a plan to start using JewelFlow. Your bills will show up here after payment.') }}</p>
                        <a href="{{ route('subscription.plans') }}" data-turbo-frame="_top" class="mt-5 inline-flex items-center rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 transition active:scale-[0.97]">
                            {{ __('Choose a plan') }}
                        </a>
                    </div>
                @else
                    @php
                        $subscription  = $subscriptionData['subscription'];
                        $plan          = $subscriptionData['plan'];
                        $daysRemaining = $subscriptionData['daysRemaining'];
                        $isInGrace     = $subscriptionData['isInGrace'];
                        $isExpired     = $subscriptionData['isExpired'];
                        $featureLabels = $subscriptionData['featureLabels'];
                        $invoices      = $subscriptionData['invoices'];

                        $rawStatus = (string) ($subscription->status ?? 'inactive');
                        $statusLabel = ucfirst($rawStatus);

                        if ($isInGrace) {
                            $statusLabel = 'Grace Period';
                        } elseif ($isExpired) {
                            $statusLabel = 'Expired';
                        }

                        $statusTone = [
                            'trial' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
                            'active' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
                            'grace period' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
                            'expired' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5'],
                            'cancelled' => ['bg' => '#f1f5f9', 'text' => '#334155', 'border' => '#cbd5e1'],
                            'suspended' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5'],
                            'read_only' => ['bg' => '#ffedd5', 'text' => '#9a3412', 'border' => '#fdba74'],
                        ][strtolower($statusLabel)] ?? ['bg' => '#f1f5f9', 'text' => '#334155', 'border' => '#cbd5e1'];

                        $startsAt = $subscription->starts_at;
                        $endsAt = $subscription->ends_at;
                        $graceEnd = $endsAt ? $endsAt->copy()->addDays((int) ($plan->grace_days ?? 7)) : null;

                        $totalDays = ($startsAt && $endsAt) ? max(1, $startsAt->diffInDays($endsAt)) : 0;
                        $usedPercent = 0;

                        if ($daysRemaining !== null && $daysRemaining >= 0 && $totalDays > 0) {
                            $usedPercent = max(0, min(100, (int) round(100 - (($daysRemaining / $totalDays) * 100))));
                        } elseif ($isExpired) {
                            $usedPercent = 100;
                        }

                        $planFeatures = is_array($plan->features)
                            ? $plan->features
                            : (json_decode((string) ($plan->features ?? '[]'), true) ?: []);

                        $billingCycle = ucfirst((string) ($subscription->billing_cycle ?? 'monthly'));
                        $pricePaid = $subscription->price_paid
                            ?? ($subscription->billing_cycle === 'yearly' ? ($plan->price_yearly ?? null) : ($plan->price_monthly ?? null));
                    @endphp

                    <div class="content-inner sub-status-page">
                        <div class="sub-status-wrap">
                            <section class="sub-hero">
                                <div class="sub-hero-top">
                                    <div>
                                        <h2 class="sub-plan-name">{{ $plan->name }}</h2>
                                        <p class="sub-plan-copy">Billed {{ strtolower($billingCycle) }} with your current access, renewal timeline, and plan limits shown below.</p>
                                    </div>
                                    <span class="sub-status-pill" style="border:1px solid {{ $statusTone['border'] }}; background: {{ $statusTone['bg'] }}; color: {{ $statusTone['text'] }};">
                                        {{ $statusLabel }}
                                    </span>
                                </div>

                                <div class="sub-kpi-grid">
                                    <div class="sub-kpi">
                                        <p class="sub-kpi-label">Plan Amount</p>
                                        <p class="sub-kpi-value">
                                            @if($pricePaid !== null)
                                                ₹{{ number_format((float) $pricePaid, 2) }}
                                            @else
                                                -
                                            @endif
                                        </p>
                                    </div>
                                    <div class="sub-kpi">
                                        <p class="sub-kpi-label">Billing Cycle</p>
                                        <p class="sub-kpi-value">{{ $billingCycle }}</p>
                                    </div>
                                    <div class="sub-kpi">
                                        <p class="sub-kpi-label">Start Date</p>
                                        <p class="sub-kpi-value">{{ $startsAt ? $startsAt->format('d M Y') : '-' }}</p>
                                    </div>
                                    <div class="sub-kpi">
                                        <p class="sub-kpi-label">
                                            @if($isInGrace)
                                                Grace Ends
                                            @elseif($isExpired)
                                                Expired On
                                            @else
                                                Renews On
                                            @endif
                                        </p>
                                        <p class="sub-kpi-value">
                                            @if($isInGrace)
                                                {{ $graceEnd ? $graceEnd->format('d M Y') : '-' }}
                                            @else
                                                {{ $endsAt ? $endsAt->format('d M Y') : '-' }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="sub-health">
                                    <div class="sub-health-head">
                                        <h3 class="sub-health-title">Plan health</h3>
                                        @if($daysRemaining !== null && $daysRemaining >= 0)
                                            <span class="sub-health-pill">{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left</span>
                                        @elseif($isInGrace)
                                            <span class="sub-health-pill" style="border-color:#f3d7a3; background:#fff7ed; color:#9a3412;">Grace active</span>
                                        @else
                                            <span class="sub-health-pill" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">Renew required</span>
                                        @endif
                                    </div>

                                    @if($daysRemaining !== null && $daysRemaining >= 0)
                                        <p class="sub-health-copy">
                                            @if($daysRemaining === 0)
                                                Your next renewal is <strong style="color:var(--sub-text);">today</strong>.
                                            @else
                                                <strong style="color:var(--sub-text);">{{ $daysRemaining }}</strong> {{ \Illuminate\Support\Str::plural('day', $daysRemaining) }} left before your next renewal.
                                            @endif
                                        </p>
                                        <div class="sub-progress-track">
                                            <div class="sub-progress-fill" style="width: {{ $usedPercent }}%;"></div>
                                        </div>
                                    @elseif($isInGrace)
                                        <p class="sub-health-copy" style="color:#92400e;">Subscription expired, but grace access stays available until {{ $graceEnd ? $graceEnd->format('d M, Y') : '-' }}.</p>
                                        <div class="sub-progress-track">
                                            <div class="sub-progress-fill" style="width:100%; background:#f59e0b;"></div>
                                        </div>
                                    @else
                                        <p class="sub-health-copy" style="color:#991b1b;">Subscription access has expired. Renew the plan to restore uninterrupted usage.</p>
                                        <div class="sub-progress-track">
                                            <div class="sub-progress-fill" style="width:100%; background:#dc2626;"></div>
                                        </div>
                                    @endif
                                </div>
                            </section>

                            <div class="sub-grid">
                                <section class="sub-card">
                                    <div class="sub-card-head">
                                        <h3 class="sub-card-title">Billing overview</h3>
                                        <p class="sub-card-copy">A compact summary of your current access state, renewal timing, and support path.</p>
                                    </div>
                                    <div class="sub-card-body">
                                        <div class="sub-detail-list">
                                            <div class="sub-detail-item">
                                                <div>
                                                    <p class="sub-detail-label">Current Status</p>
                                                    <p class="sub-detail-value">{{ $statusLabel }}</p>
                                                </div>
                                            </div>
                                            <div class="sub-detail-item">
                                                <div>
                                                    <p class="sub-detail-label">Active Window</p>
                                                    <p class="sub-detail-value">
                                                        {{ $startsAt ? $startsAt->format('d M Y') : '-' }}
                                                        <span style="color:var(--sub-text-soft); font-weight:600;">to</span>
                                                        {{ $endsAt ? $endsAt->format('d M Y') : '-' }}
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="sub-detail-item">
                                                <div>
                                                    <p class="sub-detail-label">Renewal Mode</p>
                                                    <p class="sub-detail-value">{{ $billingCycle }} billing</p>
                                                </div>
                                            </div>
                                            <div class="sub-detail-item">
                                                <div>
                                                    <p class="sub-detail-label">Support</p>
                                                    <p class="sub-detail-value">{{ config('app.support_email') }}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="sub-note">
                                            Need a different plan structure, custom limits, or renewal help? Contact support and reference your current plan name for faster help.
                                        </div>

                                        <div style="margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;">
                                            <a href="{{ route('subscription.plans') }}" data-turbo-frame="_top" class="sub-btn primary">Change Plan</a>
                                            <a href="mailto:{{ config('app.support_email') }}" class="sub-btn secondary">Contact Support</a>
                                        </div>
                                    </div>
                                </section>

                                <aside class="sub-card">
                                    <div class="sub-card-head">
                                        <h3 class="sub-card-title">Included features</h3>
                                        <p class="sub-card-copy">The tools and limits enabled under your current subscription.</p>
                                    </div>
                                    <div class="sub-card-body">
                                        <ul class="sub-feature-list">
                                            @forelse($planFeatures as $feature => $value)
                                                @if($value === false)
                                                    @continue
                                                @endif

                                                <li class="sub-feature-item">
                                                    <svg class="sub-dot" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                    </svg>
                                                    <span>
                                                        @if(is_bool($value) && $value)
                                                            {{ $featureLabels[$feature] ?? ucfirst(str_replace('_', ' ', (string) $feature)) }}
                                                        @elseif(!is_bool($value))
                                                            @if($feature === 'max_items' && (int) $value === -1)
                                                                Unlimited items
                                                            @elseif($feature === 'staff_limit')
                                                                Up to {{ $value }} staff accounts
                                                            @else
                                                                Up to {{ $value }} {{ $featureLabels[$feature] ?? \Illuminate\Support\Str::plural(str_replace('_', ' ', (string) $feature)) }}
                                                            @endif
                                                        @endif
                                                    </span>
                                                </li>
                                            @empty
                                                <li class="sub-feature-item" style="color:#64748b;">No features configured for this plan.</li>
                                            @endforelse
                                        </ul>
                                    </div>
                                </aside>
                            </div>

                            {{-- ─── Billing History ───────────────────────────────────────────── --}}
                            <section class="sub-billing-section">
                                <div class="sub-billing-head">
                                    <div>
                                        <h2 class="sub-billing-title">Billing History</h2>
                                        <p class="sub-billing-copy">All bills made for your subscription. Open any row to view or print.</p>
                                    </div>
                                </div>

                                <div class="sub-billing-card">
                                    <div class="sub-billing-table-wrap">
                                        <table class="sub-billing-table">
                                            <thead>
                                                <tr>
                                                    <th class="text-left">Invoice #</th>
                                                    <th class="text-left">Plan</th>
                                                    <th class="text-left">Cycle</th>
                                                    <th class="text-left">Period</th>
                                                    <th class="text-right">Amount</th>
                                                    <th class="text-left">Date</th>
                                                    <th class="text-left">Status</th>
                                                    <th class="text-right"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($invoices as $inv)
                                                    <tr>
                                                        <td class="sub-billing-num" data-label="Invoice #">{{ $inv->invoice_number }}</td>
                                                        <td data-label="Plan">{{ $inv->plan?->name ?? '—' }}</td>
                                                        <td class="sub-billing-capitalize" data-label="Cycle">{{ $inv->billing_cycle }}</td>
                                                        <td class="sub-billing-muted" data-label="Period">
                                                            {{ $inv->billing_period_start->format('d M Y') }}
                                                            –
                                                            {{ $inv->billing_period_end->format('d M Y') }}
                                                        </td>
                                                        <td class="text-right sub-billing-amount" data-label="Amount">₹{{ number_format($inv->total_amount, 2) }}</td>
                                                        <td class="sub-billing-muted" data-label="Date">{{ $inv->issued_at->format('d M Y') }}</td>
                                                        <td data-label="Status">
                                                            @if($inv->status === 'issued')
                                                                <span class="sub-billing-pill sub-billing-pill-paid">Paid</span>
                                                            @else
                                                                <span class="sub-billing-pill sub-billing-pill-cancelled">Cancelled</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-right sub-billing-action">
                                                            <a href="{{ route('billing.invoices.show', $inv) }}" data-turbo-frame="_top" class="sub-billing-view">View invoice</a>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="8" class="sub-billing-empty">
                                                            No bills yet. Bills appear here after each subscription payment.
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>

                                    @if($invoices->hasPages())
                                        <div class="sub-billing-pagination">
                                            {{ $invoices->links() }}
                                        </div>
                                    @endif
                                </div>
                            </section>
                        </div>
                    </div>
                @endif
            @endif

            @if($activeTab === 'devices')
                <div class="settings-header">
                    <h2 class="settings-title">{{ __('Devices') }}</h2>
                    <p class="settings-desc">{{ __('Phones that are signed in to your shop on the mobile app. Sign out any phone you do not recognise.') }}</p>
                </div>

                @if($deviceSessions->isEmpty())
                    {{-- Empty state: teach what this screen is for. --}}
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center max-w-2xl">
                        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-700">{{ __('No phones are signed in right now') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ __('A phone shows up here when your staff sign in on the mobile app.') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl">
                        @foreach($deviceSessions as $session)
                            @php
                                $person     = $session->user;
                                $personName = $person?->name ?? $person?->mobile_number ?? __('Unknown person');
                                $deviceName = $session->device_name ?: __('Unknown device');
                                $platform   = $session->platform ? ucfirst($session->platform) : null;
                            @endphp
                            <div class="bg-white rounded-xl border border-gray-200 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3 min-w-0">
                                        <div class="h-10 w-10 flex-shrink-0 rounded-full bg-teal-100 flex items-center justify-center text-teal-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-900 text-sm truncate">{{ $deviceName }}</p>
                                            <p class="text-xs text-gray-500">
                                                @if($platform){{ $platform }}@endif
                                                @if($platform && $session->app_version) · @endif
                                                @if($session->app_version){{ __('App') }} {{ $session->app_version }}@endif
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 pt-3 border-t border-gray-100 space-y-1.5 text-xs">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-500">{{ __('Signed in by') }}</span>
                                        <span class="font-medium text-gray-800 truncate">{{ $personName }}</span>
                                    </div>
                                    @if($person?->name && $person?->mobile_number)
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-gray-500">{{ __('Mobile') }}</span>
                                            <span class="text-gray-600">{{ $person->mobile_number }}</span>
                                        </div>
                                    @endif
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-500">{{ __('Logged in') }}</span>
                                        <span class="text-gray-600">{{ $session->logged_in_at ? $session->logged_in_at->diffForHumans() : '—' }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-gray-500">{{ __('Last active') }}</span>
                                        <span class="text-gray-600">{{ $session->last_seen_at ? $session->last_seen_at->diffForHumans() : ($session->logged_in_at ? $session->logged_in_at->diffForHumans() : '—') }}</span>
                                    </div>
                                    @if($session->ip_address)
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-gray-500">{{ __('From') }}</span>
                                            <span class="text-gray-400">{{ $session->ip_address }}</span>
                                        </div>
                                    @endif
                                </div>

                                @can('staff.manage')
                                    <div class="mt-3 flex justify-end">
                                        <form method="POST" action="{{ route('settings.devices.destroy', $session) }}" data-turbo-frame="_top"
                                              onsubmit="return confirm('{{ __('Sign out :device? Whoever is using this phone will need to sign in again.', ['device' => $deviceName]) }}')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-red-50 text-red-700 rounded-lg hover:bg-red-100 active:scale-[0.97] transition">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                                {{ __('Sign out this device') }}
                                            </button>
                                        </form>
                                    </div>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                @endif
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
    const removeInput = document.getElementById('shop-logo-remove');
    const deleteBtn = document.getElementById('shop-logo-delete-btn');
    const deleteLabel = document.getElementById('shop-logo-delete-label');
    const deleteNote = document.getElementById('shop-logo-delete-note');
    const initialPreviewSrc = (preview.getAttribute('src') || '').trim();
    const initialFileName = (fileName.textContent || '').trim();
    const initialDeletePending = removeInput ? removeInput.value === '1' : false;
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

    const setDeleteIntent = (enabled) => {
        if (!removeInput) {
            return;
        }

        removeInput.value = enabled ? '1' : '0';

        if (deleteBtn) {
            deleteBtn.classList.toggle('is-pending', enabled);
            deleteBtn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        }

        if (deleteLabel) {
            deleteLabel.textContent = enabled ? 'Undo' : 'Delete';
        }

        if (deleteNote) {
            deleteNote.style.display = enabled ? 'block' : 'none';
        }
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
        setDeleteIntent(false);

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
            if (removeInput && removeInput.value === '1') {
                showPlaceholder('Will be deleted after save');
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

    if (deleteBtn && removeInput) {
        deleteBtn.addEventListener('click', (event) => {
            event.preventDefault();

            const willDelete = removeInput.value !== '1';
            setDeleteIntent(willDelete);

            if (willDelete) {
                input.value = '';
                fileName.textContent = '';
                showPlaceholder('Will be deleted after save');
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

    if (initialDeletePending) {
        showPlaceholder('Will be deleted after save');
    }
})();
</script>

{{-- Self-serve "Buy & activate now" checkout. Mirrors subscription/payment.blade.php:
     fetch the initiate endpoint → open Razorpay → on success fill that card's
     hidden form and submit it to the callback (which redirects with a flash).
     One delegated handler drives every buy-now card on the page. --}}
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(() => {
    // Guard against re-binding if this script is evaluated more than once.
    if (window.__buyServiceWired) return;
    window.__buyServiceWired = true;

    const initiateUrl = @json(route('settings.services.initiate-add'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const setBusy = (btn, busy) => {
        const label = btn.querySelector('.buy-service-btn-label');
        const spinner = btn.querySelector('.buy-service-btn-spinner');
        btn.disabled = busy;
        if (spinner) spinner.classList.toggle('hidden', !busy);
        if (label) label.textContent = busy ? @json(__('Starting secure payment...')) : (btn.dataset.defaultLabel || label.textContent);
    };

    const showError = (card, message) => {
        const box = card.querySelector('.buy-service-error');
        if (!box) return;
        box.textContent = message;
        box.classList.remove('hidden');
    };

    const clearError = (card) => {
        const box = card.querySelector('.buy-service-error');
        if (box) box.classList.add('hidden');
    };

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('.buy-service-btn');
        if (!btn) return;

        const card = btn.closest('.buy-service-card');
        if (!card || btn.disabled) return;

        const product = card.dataset.product;
        // Alpine binds the live cycle onto the button via :data-cycle. Fall back to
        // monthly if the toggle was never rendered (single-cycle products).
        const cycle = btn.dataset.cycle || 'monthly';

        clearError(card);
        setBusy(btn, true);

        try {
            const res = await fetch(initiateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ product: product, billing_cycle: cycle }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                throw new Error(data.error || @json(__('Could not start payment. Please try again.')));
            }

            const options = {
                key: data.key_id,
                amount: data.amount,
                currency: data.currency,
                order_id: data.order_id,
                name: 'JewelFlow',
                description: data.plan_name,
                prefill: {
                    name: data.user_name,
                    email: data.user_email,
                    contact: data.user_contact,
                },
                theme: { color: '#0f766e' },
                handler: function (response) {
                    const form = card.querySelector('.buy-service-form');
                    form.querySelector('input[name="razorpay_payment_id"]').value = response.razorpay_payment_id;
                    form.querySelector('input[name="razorpay_order_id"]').value = response.razorpay_order_id;
                    form.querySelector('input[name="razorpay_signature"]').value = response.razorpay_signature;
                    form.submit();
                },
                modal: {
                    ondismiss: function () {
                        setBusy(btn, false);
                    }
                }
            };

            const rzp = new Razorpay(options);
            rzp.open();
        } catch (err) {
            showError(card, err.message);
            setBusy(btn, false);
        }
    });
})();
</script>
@endpush
</x-app-layout>
