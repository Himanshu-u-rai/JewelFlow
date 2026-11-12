<style>
    .staff-form-card { max-width: 56rem; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
    .staff-form-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 8px 16px; padding: 14px 18px; border-bottom: 1px solid #e5e7eb; }
    .staff-form-head h2 { font-size: 1rem; font-weight: 600; color: #111827; margin: 0; }
    .staff-form-head p { font-size: .8125rem; color: #6b7280; margin: 2px 0 0; }
    .staff-quota { font-size: .75rem; color: #4b5563; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 10px; white-space: nowrap; }
    .staff-form-body { padding: 16px 18px; }
    .staff-form-section + .staff-form-section { margin-top: 16px; padding-top: 14px; border-top: 1px solid #f3f4f6; }
    .staff-form-section-title { font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; margin: 0 0 10px; padding: 0; }
    .staff-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; }
    .staff-form-grid .staff-field-wide { grid-column: 1 / -1; }
    .staff-form-body .staff-label { display: block; font-size: .8125rem; font-weight: 500; color: #374151; margin-bottom: 4px; padding: 0; }
    .staff-form-body input[type="text"], .staff-form-body input[type="tel"], .staff-form-body input[type="email"], .staff-form-body input[type="password"] { width: 100%; }
    .staff-hint { margin-top: 3px; font-size: .75rem; color: #6b7280; }
    .staff-error { margin-top: 3px; font-size: .8125rem; color: #dc2626; }
    .staff-role-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 8px; }
    .staff-role-card { display: block; position: relative; cursor: pointer; }
    .staff-role-card > div { height: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; text-align: left; transition: background .15s, border-color .15s; }
    .staff-role-card > div:hover { background: #f9fafb; }
    .staff-role-card input:checked + div { border-color: #d97706; background: #fffbeb; box-shadow: 0 0 0 1px #d97706; }
    .staff-role-card input:focus-visible + div { outline: 2px solid #d97706; outline-offset: 2px; }
    .staff-role-name { font-size: .875rem; font-weight: 600; color: #111827; }
    .staff-role-desc { font-size: .75rem; color: #6b7280; margin-top: 2px; }
    .staff-form-footer { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e5e7eb; }
    @media (max-width: 640px) {
        .staff-form-grid { grid-template-columns: minmax(0, 1fr); }
        .staff-form-footer { flex-direction: column-reverse; align-items: stretch; }
        .staff-form-footer .btn { width: 100%; justify-content: center; }
        .staff-quota { white-space: normal; }
    }
</style>
