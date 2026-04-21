<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Staff Management</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your shop's employees and their access</p>
        </div>
        <div class="page-actions">
            @php $atLimit = $staffLimit !== -1 && $staffCount >= $staffLimit; @endphp
            @if($atLimit)
                <span class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-400 rounded-lg text-sm font-medium cursor-not-allowed"
                      title="Staff limit reached ({{ $staffLimit }}). Remove a member or contact your admin.">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    Add Staff
                </span>
            @else
                <a href="{{ route('staff.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    Add Staff
                </a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner">
        {{-- Staff limit bar --}}
        @php
            $pct      = ($staffLimit > 0) ? min(100, round($staffCount / $staffLimit * 100)) : 0;
            $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#0d9488');
        @endphp
        <div class="mb-4 flex items-center gap-4 p-3 bg-white border border-gray-200 rounded-lg text-sm">
            <span class="text-gray-600 font-medium whitespace-nowrap">Staff accounts:</span>
            @if($staffLimit === -1)
                <span class="text-gray-700">{{ $staffCount }} used &nbsp;<span class="text-green-600 font-semibold">· Unlimited</span></span>
            @else
                <div class="flex-1 flex items-center gap-3">
                    <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div style="width:{{ $pct }}%; background:{{ $barColor }};" class="h-full rounded-full transition-all"></div>
                    </div>
                    <span class="whitespace-nowrap font-semibold {{ $atLimit ? 'text-red-600' : 'text-gray-700' }}">
                        {{ $staffCount }} / {{ $staffLimit }}
                        @if($atLimit) — Limit reached @endif
                    </span>
                </div>
            @endif
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700">
                {{ session('success') }}
            </div>
        @endif
        
        @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                {{ session('error') }}
            </div>
        @endif

        <!-- Staff Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($staff as $member)
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-4">
                                <div class="h-12 w-12 rounded-full bg-amber-100 flex items-center justify-center text-amber-600 font-bold text-lg">
                                    {{ strtoupper(substr($member->name ?? $member->mobile_number, 0, 1)) }}
                                </div>
                                <div>
                                    <h3 class="font-semibold text-gray-900">{{ $member->name ?? $member->mobile_number }}</h3>
                                    @if($member->name)
                                        <p class="text-sm text-gray-500">{{ $member->mobile_number }}</p>
                                    @endif
                                </div>
                            </div>
                            @if($member->role)
                                @if($member->role->name === 'owner')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                        {{ $member->role->display_name }}
                                    </span>
                                @elseif($member->role->name === 'manager')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $member->role->display_name }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ $member->role->display_name }}
                                    </span>
                                @endif
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    No Role
                                </span>
                            @endif
                        </div>
                        
                        @if($member->email)
                            <div class="mt-4 text-sm text-gray-500">
                                <span class="inline-flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    {{ $member->email }}
                                </span>
                            </div>
                        @endif
                        
                        <div class="mt-4 pt-4 border-t border-gray-100 flex justify-between items-center">
                            <span class="text-xs text-gray-400">
                                Joined {{ $member->created_at->format('d M Y') }}
                            </span>
                            
                            <div class="flex gap-2">
                                <a href="{{ route('staff.edit', $member) }}" 
                                   class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-xs">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    Edit
                                </a>
                                
                                @if($member->id !== auth()->id())
                                    <form method="POST" action="{{ route('staff.destroy', $member) }}"
                                        data-confirm-message="Are you sure you want to remove {{ $member->name ?? $member->mobile_number }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" 
                                                class="inline-flex items-center px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 text-xs">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Remove
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400 italic">You</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No staff members yet</h3>
                    <p class="text-gray-500 mb-4">Add your first employee to get started</p>
                    @if(!$atLimit)
                    <a href="{{ route('staff.create') }}" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                        Add Staff Member
                    </a>
                    @endif
                </div>
            @endforelse
        </div>
        
        <!-- Role Legend -->
        <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Role Permissions</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="p-4 bg-purple-50 rounded-lg border border-purple-100">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl"></span>
                        <span class="font-semibold text-purple-900">Owner</span>
                    </div>
                    <ul class="text-sm text-purple-700 space-y-1">
                        <li>• Full access to all features</li>
                        <li>• View reports and analytics</li>
                        <li>• Manage staff and settings</li>
                        <li>• Export data</li>
                    </ul>
                </div>
                <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl">�</span>
                        <span class="font-semibold text-blue-900">Manager</span>
                    </div>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li>• Manage inventory and sales</li>
                        <li>• View customers and invoices</li>
                        <li>• Handle repairs</li>
                        <li>• Cannot edit settings</li>
                    </ul>
                </div>
                <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl"></span>
                        <span class="font-semibold text-gray-900">Staff</span>
                    </div>
                    <ul class="text-sm text-gray-700 space-y-1">
                        <li>• View inventory</li>
                        <li>• Process sales in POS</li>
                        <li>• View repairs</li>
                        <li>• Limited access to features</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
