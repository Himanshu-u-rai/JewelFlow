<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Staff: {{ $staff->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Update employee details and permissions</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('staff.index') }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Staff
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $staff->name }}</h2>
                    <p class="text-sm text-gray-500 mt-1">Member since {{ $staff->created_at->format('d M Y') }}</p>
                </div>
                
                <form method="POST" action="{{ route('staff.update', $staff) }}" class="p-6">
                    @csrf
                    @method('PUT')

                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="name"
                                       value="{{ old('name', $staff->name) }}"
                                       placeholder="Enter full name"
                                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('name') border-red-500 @enderror"
                                       required>
                                @error('name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="mobile_number" class="block text-sm font-medium text-gray-700 mb-2">Mobile Number <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    </div>
                                    <input type="text" name="mobile_number" id="mobile_number"
                                           value="{{ old('mobile_number', $staff->mobile_number) }}"
                                           placeholder="10-digit number for login"
                                           class="w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('mobile_number') border-red-500 @enderror"
                                           required>
                                </div>
                                @error('mobile_number')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email <span class="text-gray-400 text-xs">(Optional)</span></label>
                             <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                </div>
                                <input type="email" name="email" id="email"
                                       value="{{ old('email', $staff->email) }}"
                                       placeholder="employee@example.com"
                                       class="w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('email') border-red-500 @enderror">
                            </div>
                            @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">Role <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                @foreach($roles as $role)
                                    <label class="relative cursor-pointer">
                                        <input type="radio" name="role_id" value="{{ $role->id }}" class="peer sr-only"
                                               {{ old('role_id', $staff->role_id) == $role->id ? 'checked' : '' }}>
                                        <div class="p-4 border rounded-lg text-center peer-checked:border-amber-500 peer-checked:bg-amber-100 hover:bg-gray-50 transition-all duration-150">
                                            <div class="font-semibold text-gray-800 peer-checked:text-amber-800">{{ $role->display_name }}</div>
                                            <div class="text-xs text-gray-500 mt-1 px-2 peer-checked:text-amber-700">{{ $role->description }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            @error('role_id')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="bg-gray-50 rounded-lg p-5 border border-gray-200 mt-6">
                            <h4 class="font-semibold text-gray-800 mb-2">Change Password</h4>
                            <p class="text-sm text-gray-500 mb-4">Leave fields blank to keep the current password.</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                    <input type="password" name="password" id="password"
                                           placeholder="Min. 6 characters"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('password') border-red-500 @enderror">
                                    @error('password')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                                    <input type="password" name="password_confirmation" id="password_confirmation"
                                           placeholder="Repeat password"
                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('staff.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors font-medium inline-flex items-center">
                            Cancel
                        </a>
                        <button type="submit" 
                                class="px-6 py-2 rounded-md transition-colors font-medium inline-flex items-center" style="background: #0d9488; color: white;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>