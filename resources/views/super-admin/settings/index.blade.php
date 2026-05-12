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

        {{-- ── Maintenance Mode ─────────────────────────────────────────── --}}
        <div class="admin-panel p-6 mb-6 {{ $settings['maintenance_mode'] ? 'ring-2 ring-rose-500' : '' }}">
            <div class="mb-4 pb-4 border-b border-slate-700 flex items-center justify-between">
                <div>
                    <h4 class="text-base font-semibold {{ $settings['maintenance_mode'] ? 'text-rose-400' : 'text-slate-100' }}">
                        Maintenance Mode
                        @if($settings['maintenance_mode'])
                            <span class="ml-2 text-xs font-bold bg-rose-900 text-rose-300 border border-rose-700 rounded px-2 py-0.5">ACTIVE</span>
                        @endif
                    </h4>
                    <p class="text-sm text-slate-400 mt-1">
                        When enabled, all tenant-facing traffic returns a 503 maintenance page.
                        Admin panel access is never affected.
                    </p>
                </div>
                <div class="shrink-0">
                    <input type="hidden" name="maintenance_mode" value="0">
                    <input type="checkbox" name="maintenance_mode" value="1" id="maintenance_toggle"
                           {{ $settings['maintenance_mode'] ? 'checked' : '' }}
                           onchange="updateToggle(this)"
                           class="admin-switch-input">
                    <div id="maintenance_toggle_ui"
                         onclick="document.getElementById('maintenance_toggle').click()"
                         class="admin-switch {{ $settings['maintenance_mode'] ? 'is-on' : '' }}">
                        <div class="admin-switch-thumb"></div>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Maintenance Message (shown to users)</label>
                <input type="text" name="maintenance_message"
                       value="{{ old('maintenance_message', $settings['maintenance_message']) }}"
                       class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                       placeholder="We'll be back shortly.">
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="admin-btn admin-btn-primary px-6">Save Settings</button>
        </div>
    </form>

    {{-- ── Email / SMTP Settings (separate form) ───────────────────────── --}}
    <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-8">
        @csrf
        @method('PATCH')
        <input type="hidden" name="section" value="mail">

        <div class="admin-panel p-6">
            <div class="mb-5 pb-4 border-b border-slate-700">
                <h4 class="text-base font-semibold text-slate-100">Email / SMTP Settings</h4>
                <p class="text-sm text-slate-400 mt-1">
                    Configure the outgoing mail server. Changes apply immediately without restarting.
                    Leave <strong class="text-slate-300">Password</strong> blank to keep the existing stored password.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                {{-- Mailer --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Mailer Driver</label>
                    <select name="mail_mailer"
                            class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        @foreach(['smtp','sendmail','mailgun','ses','postmark','log','array'] as $driver)
                            <option value="{{ $driver }}" {{ $mailSettings['mail_mailer'] === $driver ? 'selected' : '' }}>
                                {{ strtoupper($driver) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- From Name --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">From Name</label>
                    <input type="text" name="mail_from_name"
                           value="{{ old('mail_from_name', $mailSettings['mail_from_name']) }}"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="JewelFlow">
                </div>

                {{-- Host --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">SMTP Host</label>
                    <input type="text" name="mail_host"
                           value="{{ old('mail_host', $mailSettings['mail_host']) }}"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="smtp.gmail.com">
                </div>

                {{-- From Address --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">From Email Address</label>
                    <input type="email" name="mail_from_address"
                           value="{{ old('mail_from_address', $mailSettings['mail_from_address']) }}"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="noreply@jewelflow.in">
                </div>

                {{-- Port --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">SMTP Port</label>
                    <input type="number" name="mail_port"
                           value="{{ old('mail_port', $mailSettings['mail_port']) }}"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="587" min="1" max="65535">
                </div>

                {{-- Encryption --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Encryption</label>
                    <select name="mail_encryption"
                            class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        @foreach(['tls' => 'TLS (recommended)', 'ssl' => 'SSL', 'starttls' => 'STARTTLS', '' => 'None'] as $val => $label)
                            <option value="{{ $val }}" {{ ($mailSettings['mail_encryption'] ?? 'tls') === $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Username --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">SMTP Username</label>
                    <input type="text" name="mail_username"
                           value="{{ old('mail_username', $mailSettings['mail_username']) }}"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="your@email.com">
                </div>

                {{-- Password --}}
                <div>
                    <label class="block text-xs text-slate-400 mb-1">
                        SMTP Password
                        @if($mailSettings['mail_password_set'])
                            <span class="ml-2 text-emerald-400 text-xs">● password stored</span>
                        @endif
                    </label>
                    <input type="password" name="mail_password"
                           class="w-full rounded border border-slate-700 bg-slate-900 text-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                           placeholder="{{ $mailSettings['mail_password_set'] ? 'Leave blank to keep existing password' : 'Enter SMTP password' }}"
                           autocomplete="new-password">
                </div>

            </div>

            @if($errors->has('mail_host') || $errors->has('mail_from_address') || $errors->has('mail_port'))
                <div class="mt-3 text-sm text-rose-400 space-y-1">
                    @foreach(['mail_mailer','mail_host','mail_port','mail_username','mail_encryption','mail_from_address','mail_from_name'] as $field)
                        @error($field)<div>{{ $message }}</div>@enderror
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end mt-5">
                <button type="submit" class="admin-btn admin-btn-primary px-6">Save Mail Settings</button>
            </div>
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
