{{--
    View-only banner — render at the top of feature pages where the
    current user lacks all write permissions for that domain.

    Usage:
        @include('partials.view-only-banner', ['permission' => 'inventory.edit'])
        @include('partials.view-only-banner', ['permission' => 'staff.manage', 'message' => 'staff management'])

    Props:
        - permission (required) — the canonical permission key to mention
        - message    (optional) — short noun phrase describing the gated area
                                  (e.g. "staff management"). Falls back to a
                                  generic message.
--}}
@php
    $bannerPermission = $permission ?? 'settings.edit';
    $bannerMessage = $message ?? null;
@endphp
<div class="view-only-banner" role="status" aria-live="polite"
     style="display:flex;align-items:center;gap:10px;padding:12px 16px;margin:0 0 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;font-size:13px;font-weight:600;">
    <x-icon name="lock" size="18" style="flex-shrink:0;" stroke="#d97706" />
    <span>
        {{ __('View only') }}@if($bannerMessage) — {{ __($bannerMessage) }}@endif.
        {{ __('Ask the shop owner to grant') }}
        <code style="background:rgba(217,119,6,0.1);padding:1px 6px;border-radius:4px;font-family:ui-monospace,monospace;font-size:12px;">{{ $bannerPermission }}</code>
        {{ __('if you need to make changes here.') }}
    </span>
</div>
