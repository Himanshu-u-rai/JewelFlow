@props(['city' => '', 'state' => '', 'pincode' => '', 'idPrefix' => 'address', 'inputClass' => 'form-input', 'labelClass' => 'form-label'])
@php
    // Same names as the existing shop onboarding dropdown; manual values remain supported.
    $states = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
        'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh',
        'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan',
        'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];
@endphp

<div {{ $attributes->class(['jf-address-fields']) }} x-data="indianAddress"
     data-lookup-url="{{ route('shops.pincode-lookup', ['pincode' => '__PIN__']) }}"
     @turbo:before-cache.document="cancel()" @turbo:before-render.document="cancel()">
    <label class="jf-address-field" for="{{ $idPrefix }}-pin">
        <span class="{{ $labelClass }}">PIN code</span>
        <input id="{{ $idPrefix }}-pin" type="text" inputmode="numeric" maxlength="12" autocomplete="postal-code"
               name="pincode" value="{{ $pincode }}" class="{{ $inputClass }}" x-ref="pin"
               @input="lookup()" @blur="if (!loading && !message) lookup()"
               aria-describedby="{{ $idPrefix }}-pin-status">
    </label>
    <label class="jf-address-field" for="{{ $idPrefix }}-city">
        <span class="{{ $labelClass }}">City / locality</span>
        <input id="{{ $idPrefix }}-city" type="text" name="city" value="{{ $city }}" autocomplete="address-level2"
               class="{{ $inputClass }}" x-ref="city" @input="edited('city')">
    </label>
    <label class="jf-address-field" for="{{ $idPrefix }}-state">
        <span class="{{ $labelClass }}">State</span>
        <input id="{{ $idPrefix }}-state" type="text" name="state" value="{{ $state }}" autocomplete="address-level1"
               list="{{ $idPrefix }}-states" placeholder="Type or choose state" class="{{ $inputClass }}" x-ref="state" @input="edited('state')">
        <datalist id="{{ $idPrefix }}-states">
            @foreach($states as $option)
                <option value="{{ $option }}"></option>
            @endforeach
        </datalist>
    </label>
    <p id="{{ $idPrefix }}-pin-status" class="jf-address-status" role="status" aria-live="polite" aria-atomic="true" x-text="message"></p>
</div>
