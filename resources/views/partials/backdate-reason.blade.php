{{--
    Back-dating speed bump (owner-approved 2026-11-16). Shows a reason field next to a date input only while that date
    falls in a FINISHED financial year (before the current one). The server is the authority
    (InvoiceAccountingService::assertBackdateAllowed); this is only a convenience.
    Usage: @include('partials.backdate-reason', ['selector' => '[name="purchase_date"]'])
    The selector may match several inputs (e.g. one per payment split); any of them in a finished year shows the field.
--}}
@php($__bdStart = \App\Support\FinancialYear::current()->startDate())
<div x-data="{
        show: false, fy: '',
        start: '{{ $__bdStart }}',
        init() {
            const form = this.$root.closest('form');
            if (!form) return;
            const check = () => {
                let found = '';
                form.querySelectorAll(@js($selector)).forEach((el) => {
                    const v = el.value || '';
                    if (!found && /^\d{4}-\d{2}-\d{2}$/.test(v) && v < this.start) {
                        const y = +v.slice(0, 4), m = +v.slice(5, 7), s = m >= 4 ? y : y - 1;
                        found = s + '-' + String(s + 1).slice(2);
                    }
                });
                this.fy = found;
                this.show = found !== '';
            };
            form.addEventListener('input', check);
            form.addEventListener('change', check);
            check();
        }
     }"
     x-show="show" style="display:none; margin-top:.5rem;" data-backdate-reason>
    @php($__bdId = 'backdate-reason-' . substr(md5($selector . uniqid('', true)), 0, 8))
    <label for="{{ $__bdId }}" style="display:block; margin:0 0 .25rem; font-size:.85rem; color:var(--warning, #92400e);">
        This date is in financial year <span x-text="fy"></span>, which has ended. Give a reason.
    </label>
    <input type="text" id="{{ $__bdId }}" name="backdate_reason" maxlength="500" minlength="10" autocomplete="off"
           value="{{ old('backdate_reason') }}" :disabled="!show" :required="show"
           placeholder="Why is this entry being dated in a finished year?"
           style="width:100%; padding:.45rem .6rem; border:1px solid var(--border, #d1d5db); border-radius:.5rem; background:var(--surface, #fff); color:inherit;">
    @error('backdate_reason')
        <p style="margin:.25rem 0 0; font-size:.8rem; color:#b91c1c;">{{ $message }}</p>
    @enderror
</div>
