{{--
    Settings → Billing → Lock books. Owner can lock/unlock (Constitution Art. VII.9: always an explicit choice);
    everyone else who can view settings sees the state only — no reasons, no forms.
--}}
@php
    $booksOwner = auth()->user()->isOwner();
    $booksShopId = (int) auth()->user()->shop_id;
    $booksLockDate = \App\Services\FinancialLockService::currentLockDate($booksShopId);
    $booksNow = \Carbon\CarbonImmutable::now();
    $booksLatest = \App\Support\LockDateRules::latestLockable($booksNow);
    $booksLastYearEnd = \App\Support\LockDateRules::lastFinancialYearEnd($booksNow);
    $booksLastYear = \App\Support\FinancialYear::forInstant($booksNow)->previous();
    $booksFmt = fn (?string $d) => $d ? \Carbon\CarbonImmutable::parse($d)->format('d M Y') : null;
    $booksErrors = $errors->has('through_date') || $errors->has('reason') || $errors->has('confirm');
@endphp
<style>
    .books-lock-state { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; font-weight:600; font-size:14px; }
    .books-lock-state.is-open { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .books-lock-state.is-locked { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .books-lock-error { color:#b91c1c; font-size:13px; margin:6px 0 0; }
    .books-lock-confirm { display:flex; align-items:flex-start; gap:8px; margin:10px 0; font-size:13px; }
    .books-lock-box { margin-top:14px; padding-top:14px; border-top:1px solid #e5e7eb; }
</style>
<section class="settings-form-section" id="lock-books" aria-label="{{ __('Lock books') }}">
    <h3 class="section-label">{{ __('Lock books') }}</h3>

    <div class="books-lock-state {{ $booksLockDate ? 'is-locked' : 'is-open' }}">
        <x-icon name="lock" size="18" />
        <span>
            @if($booksLockDate)
                {{ __('Books are locked through') }} {{ $booksFmt($booksLockDate) }}
            @else
                {{ __('Books are open') }}
            @endif
        </span>
    </div>
    <p class="field-hint" style="margin-top:8px;">
        {{ __('Nothing dated on or before this date can be added, edited or cancelled.') }}
    </p>

    @if($booksOwner)
        <p class="field-hint" style="margin-top:6px;"><a href="{{ route('books.year-end') }}" data-turbo-frame="_top">{{ __('Year-end snapshot') }}</a></p>
    @endif

    @if($booksErrors)
        @foreach(['through_date', 'reason', 'confirm'] as $k)
            @error($k)<p class="books-lock-error" role="alert">{{ $message }}</p>@enderror
        @endforeach
    @endif

    @if($booksOwner)
        <form method="POST" action="{{ route('settings.books.lock') }}" data-turbo-frame="_top" class="books-lock-box">
            @csrf
            <div class="field">
                <label class="field-label" for="books-through-date">{{ $booksLockDate ? __('Lock further, through') : __('Lock books through') }}</label>
                <input type="date" id="books-through-date" name="through_date" class="field-input"
                       value="{{ old('through_date') }}" max="{{ $booksLatest }}" @if($booksLockDate) min="{{ $booksLockDate }}" @endif>
                <span class="field-hint">{{ __('Up to yesterday at the latest. A lock can only move forward; to go back, unlock below.') }}</span>
            </div>
            <div class="field">
                <label class="field-label" for="books-lock-reason">{{ __('Note (optional)') }}</label>
                <input type="text" id="books-lock-reason" name="reason" class="field-input" maxlength="500" value="{{ old('reason') }}">
            </div>
            <label class="books-lock-confirm">
                <input type="checkbox" name="confirm" value="1" required>
                <span>{{ __('I understand that nothing dated on or before this date can be added, edited or cancelled until I unlock the books.') }}</span>
            </label>
            <button type="submit" class="btn-primary">{{ __('Lock books') }}</button>
            @if($booksLastYearEnd > ($booksLockDate ?? ''))
                <button type="submit" name="shortcut" value="last_year" class="btn-secondary" formnovalidate onclick="var c=this.form.elements.confirm; if(!c.checked){c.reportValidity();return false;}">
                    {{ __('Lock up to end of last financial year') }} ({{ $booksLastYear->label() }}, {{ $booksFmt($booksLastYearEnd) }})
                </button>
            @endif
        </form>

        @if($booksLockDate)
            <form method="POST" action="{{ route('settings.books.unlock') }}" data-turbo-frame="_top" class="books-lock-box">
                @csrf
                <h4 class="field-label">{{ __('Unlock books') }}</h4>
                <div class="field">
                    <label class="field-label" for="books-unlock-date">{{ __('Reopen from') }}</label>
                    <input type="date" id="books-unlock-date" name="through_date" class="field-input" max="{{ \Carbon\CarbonImmutable::parse($booksLockDate)->subDay()->toDateString() }}">
                    <span class="field-hint">{{ __('Leave empty to open the books completely, or pick the new lock date (earlier than the current one).') }}</span>
                </div>
                <div class="field">
                    <label class="field-label" for="books-unlock-reason">{{ __('Reason (required, at least 10 characters)') }}</label>
                    <input type="text" id="books-unlock-reason" name="reason" class="field-input" minlength="10" maxlength="500" required>
                </div>
                <label class="books-lock-confirm">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>{{ __('I want to unlock the books. This is recorded in the audit log with my name and reason.') }}</span>
                </label>
                <button type="submit" class="btn-primary">{{ __('Unlock books') }}</button>
            </form>
        @endif
    @endif
</section>
