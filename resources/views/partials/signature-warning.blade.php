{{--
    S3-04 operator warning — a signature was expected for this bill but could not
    be produced.

    Screen-only by design. The operator needs to know BEFORE handing the bill over;
    the customer's copy must not carry an internal diagnostic. The visible
    "Signature unavailable" marker inside the signature block is the part that
    prints, so the missing signature is never silently invisible either.

    Not shown on the mobile print path — that HTML goes straight to the printer
    with no screen. Mobile callers get the same state as a `signature` object on
    the JSON response instead.

    @param array{available:bool, reason:?string} $signature
--}}
@if(($signature['show'] ?? false) && ! ($signature['available'] ?? false))
<style>
    .jf-signature-warning {
        margin: 8px auto; max-width: 760px; padding: 8px 12px;
        border: 1px solid #b91c1c; border-radius: 6px;
        background: #fef2f2; color: #7f1d1d;
        font: 12px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif;
    }
    @media print { .jf-signature-warning { display: none !important; } }
</style>
<div class="jf-signature-warning" role="alert">
    <strong>Signature unavailable.</strong>
    This bill is set to print a digital signature, but the stored signature image
    could not be read
    @switch($signature['reason'] ?? null)
        @case('missing')      (the file is no longer in storage). @break
        @case('unreadable')   (the file could not be read). @break
        @case('invalid')      (the file is not a valid image). @break
        @case('too_large')    (the file exceeds the allowed size). @break
        @case('bad_disk')     (the stored location is not recognised). @break
        @case('bad_path')     (the stored location is not valid). @break
        @default              . @break
    @endswitch
    The bill is otherwise complete and can still be issued. Re-upload the signature
    under Settings → Billing to restore it on future bills.
</div>
@endif
