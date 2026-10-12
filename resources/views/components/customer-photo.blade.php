@props(['customer' => null, 'realm' => 'erp', 'mode' => 'profile'])
@php
    $photo = $customer?->profilePhoto;
    $permission = $mode === 'form' ? ($customer ? 'customers.edit' : 'customers.create') : ($realm === 'dhiran' ? 'dhiran.create' : 'customers.edit');
    $canChange = auth()->user()?->can($permission);
    $id = 'customer-photo-'.$realm.'-'.($customer?->id ?? 'new').'-'.$mode;
    $url = $customer?->exists ? route(($realm === 'dhiran' ? 'dhiran.borrowers' : 'customers').'.photo.show', $customer) : '';
    $actions = [
        'camera' => ['Take photo', 'M14.5 4h-5L7.5 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3.5ZM16 13a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z'],
        'upload' => ['Upload photo', 'M12 16V3m-4 4 4-4 4 4M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4'],
        'change' => ['Change photo', 'm16 3 5 5M4 20l4-1L21 6a2 2 0 0 0-3-3L5 16ZM13 20h8'],
        'remove' => ['Remove photo', 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7'],
    ];
@endphp
<section class="customer-photo" data-customer-photo data-photo-mode="{{ $mode }}" data-photo-url="{{ $url }}" data-photo-version="{{ $photo?->version ?? '' }}" aria-label="Customer photo">
    <div class="customer-photo__identity">
        <x-customer-avatar :customer="$customer" :realm="$realm" size="profile" />
        <div class="customer-photo__copy">
            <div class="customer-photo__label">Customer photo <span>Optional</span></div>
            <p>Private to your shop. Separate from KYC documents.</p>
            @if($canChange)
                <div class="customer-photo__actions">
                    @foreach($actions as $action => [$label, $path])
                        <button type="button" class="btn btn-secondary customer-photo-icon {{ $action === 'remove' ? 'customer-photo__remove' : '' }}" data-photo-action="{{ $action }}" aria-label="{{ $label }}" title="{{ $label }}" @if(!$photo && in_array($action, ['change', 'remove'])) hidden @endif>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}" /></svg>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <p class="customer-photo__status" data-photo-status role="status" aria-live="polite"></p>
    @error('customer_photo')<p class="customer-photo__error" role="alert">{{ $message }}</p>@enderror
    @error('customer_photo_version')<p class="customer-photo__error" role="alert">{{ $message }}</p>@enderror
    @if($canChange)
        @if($mode === 'form')
            <input type="file" name="customer_photo" data-photo-form-file hidden accept="image/jpeg,image/png,image/webp">
            <input type="hidden" name="customer_photo_version" data-photo-form-version value="{{ old('customer_photo_version', $photo?->version ?? '') }}">
            <input type="hidden" name="customer_photo_remove" data-photo-form-remove value="0">
            <noscript><p>JavaScript is needed for camera previews. Choose an optional photo here:</p><input type="file" name="customer_photo" accept="image/jpeg,image/png,image/webp"></noscript>
        @endif
        <input type="file" data-photo-picker hidden accept="image/jpeg,image/png,image/webp">
        <dialog class="customer-photo-dialog" data-photo-dialog aria-labelledby="{{ $id }}-title">
            <header class="customer-photo-dialog__header">
                <h2 id="{{ $id }}-title">Customer photo</h2>
                <button type="button" class="btn btn-secondary btn-sm" data-photo-action="cancel" aria-label="Close photo preview">Close</button>
            </header>
            <div class="customer-photo-dialog__body">
                <p data-photo-help>Take a photo or choose an image. Preview it before saving.</p>
                <p class="customer-photo__error" data-photo-error role="alert" tabindex="-1" hidden></p>
                <video data-photo-video playsinline muted autoplay hidden aria-label="Live camera preview"></video>
                <img data-photo-preview alt="Selected customer photo preview" hidden>
                <div class="customer-photo-dialog__choices">
                    @foreach(array_slice($actions, 0, 2, true) as $action => [$label, $path])
                        <button type="button" class="btn btn-secondary customer-photo-icon" data-photo-action="{{ $action }}" aria-label="{{ $label }}" title="{{ $label }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}" /></svg>
                        </button>
                    @endforeach
                    <button type="button" class="btn btn-primary" data-photo-action="capture" hidden>Capture</button>
                    <button type="button" class="btn btn-secondary" data-photo-action="retake" hidden>Retake</button>
                </div>
                <p class="customer-photo-dialog__limits">JPEG, PNG or WebP · up to 5 MB / 20 megapixels.<br>Saved privately as an optimized image, without location metadata.</p>
            </div>
            <footer class="customer-photo-dialog__footer">
                <button type="button" class="btn btn-secondary" data-photo-action="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" data-photo-action="confirm" hidden>{{ $mode === 'form' ? 'Use photo' : 'Save photo' }}</button>
            </footer>
        </dialog>
    @endif
</section>
