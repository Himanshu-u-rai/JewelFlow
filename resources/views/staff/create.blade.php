<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Add Staff Member</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new employee account</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('staff.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                Back to Staff
            </a>
        </div>
    </x-page-header>

    @include('staff._form-styles')

    <div class="content-inner">
        @php
            $staffLimit = $shop->staffLimit();
            $staffCount = $shop->currentStaffCount();
        @endphp

        <div class="staff-form-card">
            <div class="staff-form-head">
                <div>
                    <h2>New Staff Member</h2>
                    <p>Fill in staff profile and permissions role.</p>
                </div>
                <div class="staff-quota">Staff accounts:
                    <strong>{{ $staffCount }}{{ $staffLimit !== -1 ? ' / '.$staffLimit : '' }}</strong>
                    @if($staffLimit !== -1)
                        &middot;
                        <span class="{{ ($staffLimit - $staffCount) <= 1 ? 'text-amber-600 font-semibold' : 'text-gray-500' }}">
                            {{ $staffLimit - $staffCount }} slot(s) remaining after this
                        </span>
                    @else
                        &middot; <span class="text-green-600 font-semibold">Unlimited</span>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('staff.store') }}" class="staff-form-body">
                @csrf

                <div class="staff-form-section">
                    <h3 class="staff-form-section-title">Identity and contact</h3>
                    <div class="staff-form-grid">
                        <div class="staff-field-wide">
                            <label for="name" class="staff-label">Full Name *</label>
                            <input type="text" name="name" id="name"
                                   value="{{ old('name') }}"
                                   placeholder="Enter full name"
                                   class="border-gray-300 shadow-sm"
                                   required>
                            @error('name')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="mobile_number" class="staff-label">Mobile Number *</label>
                            <input type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}"
                                   name="mobile_number" id="mobile_number"
                                   value="{{ old('mobile_number') }}"
                                   placeholder="e.g., 9876543210"
                                   class="border-gray-300 shadow-sm"
                                   required>
                            <p class="staff-hint">Used for login</p>
                            @error('mobile_number')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="staff-label">Email (Optional)</label>
                            <input type="email" name="email" id="email"
                                   value="{{ old('email') }}"
                                   placeholder="employee@example.com"
                                   class="border-gray-300 shadow-sm">
                            @error('email')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="staff-form-section">
                    <h3 class="staff-form-section-title">Role and security</h3>
                    <fieldset style="border:0;padding:0;margin:0 0 12px;min-width:0;">
                        <legend class="staff-label">Role *</legend>
                        <div class="staff-role-grid">
                            @foreach($roles as $role)
                                <label class="staff-role-card">
                                    <input type="radio" name="role_id" value="{{ $role->id }}" class="sr-only"
                                           {{ old('role_id') == $role->id || ($loop->first && !old('role_id')) ? 'checked' : '' }}>
                                    <div>
                                        <div class="staff-role-name">{{ $role->display_name }}</div>
                                        <div class="staff-role-desc">{{ Str::limit($role->description, 30) }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('role_id')
                            <p class="staff-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <div class="staff-form-grid">
                        <div>
                            <label for="password" class="staff-label">Password *</label>
                            <input type="password" name="password" id="password"
                                   placeholder="Min 6 characters"
                                   class="border-gray-300 shadow-sm"
                                   required>
                            @error('password')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="password_confirmation" class="staff-label">Confirm Password *</label>
                            <input type="password" name="password_confirmation" id="password_confirmation"
                                   placeholder="Repeat password"
                                   class="border-gray-300 shadow-sm"
                                   required>
                        </div>
                    </div>
                </div>

                <div class="staff-form-footer">
                    <a href="{{ route('staff.index') }}" class="btn btn-secondary btn-sm">
                        <x-icon name="x" size="14" class="mr-1" />
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="user-plus" size="14" class="mr-1" />
                        Add Staff Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
