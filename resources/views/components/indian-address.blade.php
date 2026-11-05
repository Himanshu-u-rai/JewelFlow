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
     @turbo:before-cache.document="cancel()" @turbo:before-render.document="cancel()"
     @resize.window="if (stateOpen) placeStates()" @scroll.window.capture="if (stateOpen) placeStates()">
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
    <div class="jf-address-field">
        <label for="{{ $idPrefix }}-state" class="{{ $labelClass }}">State</label>
        <div class="jf-state-control">
        <input id="{{ $idPrefix }}-state" type="text" name="state" value="{{ $state }}" autocomplete="off"
               list="{{ $idPrefix }}-states" placeholder="Type or choose state" class="{{ $inputClass }}" x-ref="state"
               role="combobox" aria-autocomplete="list" aria-expanded="false" :aria-expanded="stateOpen"
               aria-controls="{{ $idPrefix }}-state-menu" :aria-activedescendant="stateOpen && activeState >= 0 ? '{{ $idPrefix }}-state-option-' + activeState : null"
               @focus="openStates()" @click="if (!stateOpen) openStates()" @blur="closeStates()"
               @input="edited('state'); if ($refs.state.matches(':focus')) openStates(true)"
               @keydown.arrow-down="if (enhanced) { $event.preventDefault(); moveState(1); }"
               @keydown.arrow-up="if (enhanced) { $event.preventDefault(); moveState(-1); }"
               @keydown.enter="if (stateOpen) { $event.preventDefault(); chooseState(); }"
               @keydown.escape="if (stateOpen) { $event.preventDefault(); $event.stopPropagation(); closeStates(); }" @keydown.tab="closeStates()">
            <x-icon name="chevron-down" size="16" class="jf-state-chevron" aria-hidden="true" x-cloak x-show="enhanced" />
        </div>
        <datalist id="{{ $idPrefix }}-states" x-ref="states">
            @foreach($states as $option)
                <option value="{{ $option }}"></option>
            @endforeach
        </datalist>
        <div id="{{ $idPrefix }}-state-menu" class="jf-state-menu jf-scrollbar" popover="auto" role="listbox"
             aria-label="States and union territories" x-ref="stateMenu"
             @toggle="stateOpen = $event.newState === 'open'; if (stateOpen) placeStates(); else activeState = -1">
            <template x-for="(name, index) in stateMatches" :key="name">
                <button type="button" role="option" tabindex="-1" :id="'{{ $idPrefix }}-state-option-' + index"
                        :data-index="index" :aria-selected="activeState === index" :class="{ 'is-active': activeState === index }"
                        @pointerdown.prevent @click="chooseState(name)" x-text="name"></button>
            </template>
            <p x-show="stateMatches.length === 0">No match. You can keep your typed state.</p>
        </div>
    </div>
    <div class="jf-address-status-row">
        <p id="{{ $idPrefix }}-pin-status" class="jf-address-status" role="status" aria-live="polite" aria-atomic="true" x-text="message"></p>
        <button type="button" class="jf-address-retry" x-cloak x-show="lookupFailed" @click="lookup()">Retry PIN lookup</button>
    </div>
</div>
