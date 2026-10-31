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

    <div class="content-inner">
        @php
            $staffLimit = $shop->staffLimit();
            $staffCount = $shop->currentStaffCount();
        @endphp
        <div class="max-w-2xl mx-auto mb-3 flex items-center justify-between px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-600">
            <span>Staff accounts:
                <strong>{{ $staffCount }}{{ $staffLimit !== -1 ? ' / '.$staffLimit : '' }}</strong>
                @if($staffLimit !== -1)
                    &nbsp;·&nbsp;
                    <span class="{{ ($staffLimit - $staffCount) <= 1 ? 'text-amber-600 font-semibold' : 'text-gray-500' }}">
                        {{ $staffLimit - $staffCount }} slot(s) remaining after this
                    </span>
                @else
                    &nbsp;·&nbsp; <span class="text-green-600 font-semibold">Unlimited</span>
                @endif
            </span>
        </div>

        <div class="max-w-2xl mx-auto bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-5 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">New Staff Member</h2>
                <p class="text-sm text-gray-500 mt-1">Fill in staff profile and permissions role.</p>
            </div>

            <form method="POST" action="{{ route('staff.store') }}" class="p-6 space-y-6">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                    <input type="text" name="name" id="name"
                           value="{{ old('name') }}"
                           placeholder="Enter full name"
                           class="w-full border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                           required>
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="mobile_number" class="block text-sm font-medium text-gray-700 mb-2">Mobile Number *</label>
                    <input type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}"
                           name="mobile_number" id="mobile_number"
                           value="{{ old('mobile_number') }}"
                           placeholder="e.g., 9876543210"
                           class="w-full border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                           required>
                    <p class="mt-1 text-sm text-gray-500">Used for login</p>
                    @error('mobile_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email (Optional)</label>
                    <input type="email" name="email" id="email"
                           value="{{ old('email') }}"
                           placeholder="employee@example.com"
                           class="w-full border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500">
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Role *</label>
                    <div class="grid grid-cols-3 gap-4">
                        @foreach($roles as $role)
                            <label class="relative cursor-pointer">
                                <input type="radio" name="role_id" value="{{ $role->id }}" class="peer sr-only"
                                       {{ old('role_id') == $role->id || ($loop->first && !old('role_id')) ? 'checked' : '' }}>
                                <div class="p-4 border text-center peer-checked:border-gray-900 peer-checked:bg-gray-50 hover:bg-gray-50 transition">
                                    <div class="font-medium text-gray-900">{{ $role->display_name }}</div>
                                    <div class="text-xs text-gray-500 mt-1">{{ Str::limit($role->description, 30) }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('role_id')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                        <input type="password" name="password" id="password"
                               placeholder="Min 6 characters"
                               class="w-full border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                               required>
                        @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password *</label>
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               placeholder="Repeat password"
                               class="w-full border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                               required>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                    <a href="{{ route('staff.index') }}" class="btn btn-secondary btn-sm">
                        <x-icon name="x" size="14" class="mr-1" />
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-dark btn-sm">
                        <x-icon name="user-plus" size="14" class="mr-1" />
                        Add Staff Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>