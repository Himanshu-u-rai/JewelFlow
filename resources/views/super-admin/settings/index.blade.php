<x-super-admin.layout>
    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PATCH')

        {{-- ── Shop Type Availability ───────────────────────────────────── --}}
        <div class="admin-panel p-6 mb-6">
            <div class="mb-5 pb-4 border-b border-slate-700">
                <h4 class="text-base font-semibold text-slate-100">Shop Type Availability</h4>
                <p class="text-sm text-slate-400 mt-1">
                    Control which business types new users can register as.
                    Disabling a type hides the "Choose Shop Type" screen and routes new users directly
                    to plans for the remaining enabled type.
                    <strong class="text-amber-400">At least one type must always remain enabled.</strong>
                </p>
            </div>

            <div class="admin-settings-grid">

                {{-- Retailer --}}
                <div class="admin-setting-card {{ $settings['retailer_enabled'] ? 'is-active' : '' }}">
                    <div class="admin-setting-card-head">
                        <div class="admin-setting-card-body">
                            <div class="admin-setting-title-row">
                                <span class="admin-setting-icon">🏪</span>
                                <span class="admin-setting-title">Retailer</span>
                                @if($settings['retailer_enabled'])
                                    <span class="admin-setting-status is-active">Enabled</span>
                                @else
                                    <span class="admin-setting-status is-inactive">Disabled</span>
                                @endif
                            </div>
                            <p class="admin-setting-copy">
                                Jewellery shops that buy &amp; sell. New registrations use <strong class="text-slate-200">retailer plans only</strong>.
                            </p>
                        </div>

                        {{-- Toggle --}}
                        <div class="shrink-0 mt-0.5">
                            <input type="hidden" name="retailer_enabled" value="0">
                            <input type="checkbox" name="retailer_enabled" value="1" id="retailer_toggle"
                                   {{ $settings['retailer_enabled'] ? 'checked' : '' }}
                                   onchange="updateToggle(this)"
                                   class="admin-switch-input">
                            <div id="retailer_toggle_ui"
                                 onclick="document.getElementById('retailer_toggle').click()"
                                 class="admin-switch {{ $settings['retailer_enabled'] ? 'is-on' : '' }}">
                                <div class="admin-switch-thumb"></div>
                            </div>
                        </div>
                    </div>
                    <div class="admin-setting-note">
                        <div>Plans affected: plans whose code contains <code>retailer</code></div>
                        <div class="mt-1">When disabled: new users cannot select Retailer; existing shops are unaffected.</div>
                    </div>
                </div>

                {{-- Manufacturer --}}
                <div class="admin-setting-card {{ $settings['manufacturer_enabled'] ? 'is-active' : '' }}">
                    <div class="admin-setting-card-head">
                        <div class="admin-setting-card-body">
                            <div class="admin-setting-title-row">
                                <span class="admin-setting-icon">🏭</span>
                                <span class="admin-setting-title">Manufacturer</span>
                                @if($settings['manufacturer_enabled'])
                                    <span class="admin-setting-status is-active">Enabled</span>
                                @else
                                    <span class="admin-setting-status is-inactive">Disabled</span>
                                @endif
                            </div>
                            <p class="admin-setting-copy">
                                Jewellery makers &amp; wholesalers. New registrations use <strong class="text-slate-200">manufacturer plans only</strong>.
                            </p>
                        </div>

                        {{-- Toggle --}}
                        <div class="shrink-0 mt-0.5">
                            <input type="hidden" name="manufacturer_enabled" value="0">
                            <input type="checkbox" name="manufacturer_enabled" value="1" id="manufacturer_toggle"
                                   {{ $settings['manufacturer_enabled'] ? 'checked' : '' }}
                                   onchange="updateToggle(this)"
                                   class="admin-switch-input">
                            <div id="manufacturer_toggle_ui"
                                 onclick="document.getElementById('manufacturer_toggle').click()"
                                 class="admin-switch {{ $settings['manufacturer_enabled'] ? 'is-on' : '' }}">
                                <div class="admin-switch-thumb"></div>
                            </div>
                        </div>
                    </div>
                    <div class="admin-setting-note">
                        <div>Plans affected: plans whose code contains <code>manufacturer</code></div>
                        <div class="mt-1">When disabled: new users cannot select Manufacturer; existing shops are unaffected.</div>
                    </div>
                </div>

                {{-- Dhiran --}}
                <div class="admin-setting-card {{ $settings['dhiran_enabled'] ? 'is-active' : '' }}">
                    <div class="admin-setting-card-head">
                        <div class="admin-setting-card-body">
                            <div class="admin-setting-title-row">
                                <span class="admin-setting-icon">💰</span>
                                <span class="admin-setting-title">Dhiran</span>
                                @if($settings['dhiran_enabled'])
                                    <span class="admin-setting-status is-active">Enabled</span>
                                @else
                                    <span class="admin-setting-status is-inactive">Disabled</span>
                                @endif
                            </div>
                            <p class="admin-setting-copy">
                                Gold-loan / pledge management. Can be picked standalone or alongside Retailer / Manufacturer.
                            </p>
                        </div>

                        {{-- Toggle --}}
                        <div class="shrink-0 mt-0.5">
                            <input type="hidden" name="dhiran_enabled" value="0">
                            <input type="checkbox" name="dhiran_enabled" value="1" id="dhiran_toggle"
                                   {{ $settings['dhiran_enabled'] ? 'checked' : '' }}
                                   onchange="updateToggle(this)"
                                   class="admin-switch-input">
                            <div id="dhiran_toggle_ui"
                                 onclick="document.getElementById('dhiran_toggle').click()"
                                 class="admin-switch {{ $settings['dhiran_enabled'] ? 'is-on' : '' }}">
                                <div class="admin-switch-thumb"></div>
                            </div>
                        </div>
                    </div>
                    <div class="admin-setting-note">
                        <div>When disabled: new users cannot select Dhiran; existing Dhiran shops are unaffected.</div>
                    </div>
                </div>

            </div>

            {{-- Effective behaviour summary --}}
            <div class="admin-setting-summary">
                @php
                    $enabledLabels = array_filter([
                        $settings['retailer_enabled'] ? 'Retailer' : null,
                        $settings['manufacturer_enabled'] ? 'Manufacturer' : null,
                        $settings['dhiran_enabled'] ? 'Dhiran' : null,
                    ]);
                @endphp
                <span><strong>Current behaviour for new registrations:</strong> </span>
                @if(count($enabledLabels) === 0)
                    <span class="font-semibold text-rose-400">⚠ No edition enabled — save will be blocked until at least one is enabled.</span>
                @elseif(count($enabledLabels) === 1)
                    Only {{ $enabledLabels[array_key_first($enabledLabels)] }} enabled — the choose-services screen is skipped; users go directly to plans.
                @else
                    Enabled editions: {{ implode(', ', $enabledLabels) }} — users pick any combination on the choose-services screen.
                @endif
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="admin-btn admin-btn-primary px-6">Save Settings</button>
        </div>
    </form>

    <script>
        function updateToggle(checkbox) {
            const id = checkbox.id; // e.g. "retailer_toggle"
            const ui = document.getElementById(id + '_ui');
            if (checkbox.checked) {
                ui.classList.add('is-on');
            } else {
                ui.classList.remove('is-on');
            }
        }
    </script>
</x-super-admin.layout>
