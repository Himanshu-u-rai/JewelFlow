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
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         style="flex-shrink:0;" aria-hidden="true">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </svg>
    <span>
        {{ __('View only') }}@if($bannerMessage) — {{ __($bannerMessage) }}@endif.
        {{ __('Ask the shop owner to grant') }}
        <code style="background:rgba(217,119,6,0.1);padding:1px 6px;border-radius:4px;font-family:ui-monospace,monospace;font-size:12px;">{{ $bannerPermission }}</code>
        {{ __('if you need to make changes here.') }}
    </span>
</div>
