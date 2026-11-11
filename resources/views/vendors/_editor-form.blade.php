<turbo-frame id="vendor-editor-frame" x-ref="frame">
    <form method="POST" action="{{ $vendor ? route('vendors.update', $vendor) : route('vendors.store') }}" class="vendor-form" data-turbo-frame="_top">
        @csrf
        @if($vendor)<input type="hidden" name="_method" value="PUT">@endif
        <input type="hidden" name="_vendor_editor" value="1">
        <div class="vendor-form-body">
            @if($errors->any())
                <div class="vendor-editor-errors" role="alert" tabindex="-1" data-editor-errors>
                    <p>Please review these fields. Your entered values have been kept.</p>
                    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="vendor-form-grid">
                <div>
                    <label for="vendor-name">Business Name <span aria-hidden="true">*</span></label>
                    <input id="vendor-name" class="vendor-field-input" type="text" name="name" value="{{ old('name', $vendor?->name) }}" required maxlength="255" autocomplete="organization" @error('name') aria-invalid="true" aria-describedby="vendor-name-error" @enderror>
                    @error('name')<p id="vendor-name-error" class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-contact">Contact Person</label>
                    <input id="vendor-contact" class="vendor-field-input" type="text" name="contact_person" value="{{ old('contact_person', $vendor?->contact_person) }}" maxlength="255" autocomplete="name">
                    @error('contact_person')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-mobile">Mobile</label>
                    <input id="vendor-mobile" class="vendor-field-input" type="tel" inputmode="numeric" name="mobile" value="{{ old('mobile', $vendor?->mobile) }}" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile" autocomplete="tel-national">
                    @error('mobile')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-email">Email</label>
                    <input id="vendor-email" class="vendor-field-input" type="email" name="email" value="{{ old('email', $vendor?->email) }}" maxlength="255" autocomplete="email">
                    @error('email')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="vendor-location-grid">
                <div>
                    <label for="vendor-city">City</label>
                    <input id="vendor-city" class="vendor-field-input" type="text" name="city" value="{{ old('city', $vendor?->city) }}" maxlength="100" autocomplete="address-level2">
                    @error('city')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-state">State</label>
                    <input id="vendor-state" class="vendor-field-input" type="text" name="state" value="{{ old('state', $vendor?->state) }}" maxlength="100" autocomplete="address-level1">
                    @error('state')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-gst">GST Number</label>
                    <input id="vendor-gst" class="vendor-field-input" type="text" name="gst_number" value="{{ old('gst_number', $vendor?->gst_number) }}" maxlength="15" placeholder="22AAAAA0000A1Z5" @error('gst_number') aria-invalid="true" aria-describedby="vendor-gst-error" @enderror>
                    @error('gst_number')<p id="vendor-gst-error" class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="vendor-form-grid">
                <div>
                    <label for="vendor-address">Address</label>
                    <textarea id="vendor-address" class="vendor-field-input" name="address" rows="2" maxlength="1000" autocomplete="street-address">{{ old('address', $vendor?->address) }}</textarea>
                    @error('address')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="vendor-notes">Notes</label>
                    <textarea id="vendor-notes" class="vendor-field-input" name="notes" rows="2" maxlength="2000">{{ old('notes', $vendor?->notes) }}</textarea>
                    @error('notes')<p class="vendor-field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            @if($vendor)
                <p class="vendor-availability">{{ $vendor->is_active ? 'Active vendor.' : 'Archived vendor.' }} Saving details does not change availability.</p>
            @else
                <p class="vendor-availability">Only Business Name is required.</p>
            @endif
        </div>
        <footer class="vendor-form-actions">
            <button type="button" class="btn btn-secondary" @click="close()" :disabled="submitting">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="submitting" x-text="submitting ? 'Saving…' : '{{ $vendor ? 'Update Vendor' : 'Save Vendor' }}'">{{ $vendor ? 'Update Vendor' : 'Save Vendor' }}</button>
        </footer>
    </form>
</turbo-frame>
