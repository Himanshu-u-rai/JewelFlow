@props(['customer' => null, 'realm' => 'erp', 'mode' => 'profile'])
@php
    $photo = $customer?->profilePhoto;
    $permission = $mode === 'form' ? ($customer ? 'customers.edit' : 'customers.create') : ($realm === 'dhiran' ? 'dhiran.create' : 'customers.edit');
    $canChange = auth()->user()?->can($permission);
    $id = 'customer-photo-'.$realm.'-'.($customer?->id ?? 'new').'-'.$mode;
    $url = $customer?->exists ? route(($realm === 'dhiran' ? 'dhiran.borrowers' : 'customers').'.photo.show', $customer) : '';
    $actions = [
        'camera' => ['Take photo', 'camera'],
        'upload' => ['Upload photo', 'upload'],
        'change' => ['Change photo', 'pencil'],
        'remove' => ['Remove photo', 'trash-2'],
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
                    @foreach($actions as $action => [$label, $icon])
                        <button type="button" class="btn btn-secondary customer-photo-icon {{ $action === 'remove' ? 'customer-photo__remove' : '' }}" data-photo-action="{{ $action }}" aria-label="{{ $label }}" title="{{ $label }}" @if(!$photo && in_array($action, ['change', 'remove'])) hidden @endif>
                            <x-icon :name="$icon" stroke-width="1.75" />
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
                    @foreach(array_slice($actions, 0, 2, true) as $action => [$label, $icon])
                        <button type="button" class="btn btn-secondary customer-photo-icon" data-photo-action="{{ $action }}" aria-label="{{ $label }}" title="{{ $label }}">
                            <x-icon :name="$icon" stroke-width="1.75" />
                        </button>
                    @endforeach
                    <button type="button" class="btn btn-secondary customer-photo-icon customer-photo-switch" data-photo-action="switch" aria-label="Switch to front camera" title="Switch to front camera" hidden>
                        <x-icon name="switch-camera" stroke-width="1.75" />
                    </button>
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
