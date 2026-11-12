<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Staff: {{ $staff->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Update employee details and permissions</p>
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
        <div class="staff-form-card">
            <div class="staff-form-head">
                <div>
                    <h2>{{ $staff->name }}</h2>
                    <p>Member since {{ $staff->created_at->format('d M Y') }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('staff.update', $staff) }}" class="staff-form-body">
                @csrf
                @method('PUT')

                <div class="staff-form-section">
                    <h3 class="staff-form-section-title">Identity and contact</h3>
                    <div class="staff-form-grid">
                        <div>
                            <label for="name" class="staff-label">Full Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="name"
                                   value="{{ old('name', $staff->name) }}"
                                   placeholder="Enter full name"
                                   class="rounded-md border-gray-300 shadow-sm @error('name') border-red-500 @enderror"
                                   required>
                            @error('name')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="mobile_number" class="staff-label">Mobile Number <span class="text-red-500">*</span></label>
                            <input type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}"
                                   name="mobile_number" id="mobile_number"
                                   value="{{ old('mobile_number', $staff->mobile_number) }}"
                                   placeholder="10-digit number for login"
                                   class="rounded-md border-gray-300 shadow-sm @error('mobile_number') border-red-500 @enderror"
                                   required>
                            @error('mobile_number')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="staff-field-wide">
                            <label for="email" class="staff-label">Email <span class="text-gray-400 text-xs">(Optional)</span></label>
                            <input type="email" name="email" id="email"
                                   value="{{ old('email', $staff->email) }}"
                                   placeholder="employee@example.com"
                                   class="rounded-md border-gray-300 shadow-sm @error('email') border-red-500 @enderror">
                            @error('email')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="staff-form-section">
                    <h3 class="staff-form-section-title">Role and security</h3>
                    <fieldset style="border:0;padding:0;margin:0 0 12px;min-width:0;">
                        <legend class="staff-label">Role <span class="text-red-500">*</span></legend>
                        <div class="staff-role-grid">
                            @foreach($roles as $role)
                                <label class="staff-role-card">
                                    <input type="radio" name="role_id" value="{{ $role->id }}" class="sr-only"
                                           {{ old('role_id', $staff->role_id) == $role->id ? 'checked' : '' }}>
                                    <div>
                                        <div class="staff-role-name">{{ $role->display_name }}</div>
                                        <div class="staff-role-desc">{{ $role->description }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('role_id')
                            <p class="staff-error">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <p class="staff-hint" style="margin:0 0 8px;">Change password: leave fields blank to keep the current password.</p>
                    <div class="staff-form-grid">
                        <div>
                            <label for="password" class="staff-label">New Password</label>
                            <input type="password" name="password" id="password"
                                   placeholder="Min. 6 characters"
                                   class="rounded-md border-gray-300 shadow-sm @error('password') border-red-500 @enderror">
                            @error('password')
                                <p class="staff-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="password_confirmation" class="staff-label">Confirm New Password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation"
                                   placeholder="Repeat password"
                                   class="rounded-md border-gray-300 shadow-sm">
                        </div>
                    </div>
                </div>

                <div class="staff-form-footer">
                    <a href="{{ route('staff.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="save" size="14" class="mr-1" />
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
