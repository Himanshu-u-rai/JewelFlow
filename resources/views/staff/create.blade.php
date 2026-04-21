<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Add Staff Member</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new employee account</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('staff.index') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
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
                    <input type="text" name="mobile_number" id="mobile_number"
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-dark btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        Add Staff Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>