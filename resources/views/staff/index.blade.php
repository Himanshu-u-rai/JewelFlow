<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Staff Management</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your shop's employees and their access</p>
        </div>
        <div class="page-actions">
            @php $atLimit = $staffLimit !== -1 && $staffCount >= $staffLimit; @endphp
            @can('staff.manage')
                @if($atLimit)
                    <span class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-400 rounded-lg text-sm font-medium cursor-not-allowed"
                          title="Staff limit reached ({{ $staffLimit }}). Remove a member or contact your admin.">
                        <x-icon name="user-plus" class="w-4 h-4 mr-2" />
                        Add Staff
                    </span>
                @else
                    <a href="{{ route('staff.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm font-medium">
                        <x-icon name="user-plus" class="w-4 h-4 mr-2" />
                        Add Staff
                    </a>
                @endif
            @endcan
        </div>
    </x-page-header>

    <div class="content-inner">
        @unless(auth()->user()->can('staff.manage'))
            @include('partials.view-only-banner', ['permission' => 'staff.manage', 'message' => 'staff management'])
        @endunless
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
                                    <h3 class="font-semibold text-gray-900">{{ $member->name ?? \App\Support\Mobile::forDisplay($member->mobile_number) }}</h3>
                                    @if($member->name)
                                        <p class="text-sm text-gray-500">{{ \App\Support\Mobile::forDisplay($member->mobile_number) }}</p>
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
                                    <x-icon name="mail" class="w-4 h-4 mr-1" />
                                    {{ $member->email }}
                                </span>
                            </div>
                        @endif
                        
                        <div class="mt-4 pt-4 border-t border-gray-100 flex justify-between items-center">
                            <span class="text-xs text-gray-400">
                                Joined {{ $member->created_at->format('d M Y') }}
                            </span>
                            
                            <div class="flex gap-2">
                                @can('staff.manage')
                                <a href="{{ route('staff.edit', $member) }}"
                                   class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-xs">
                                    <x-icon name="square-pen" class="w-3 h-3 mr-1" />
                                    Edit
                                </a>

                                @if($member->id !== auth()->id())
                                    <form method="POST" action="{{ route('staff.destroy', $member) }}"
                                        data-confirm-message="Are you sure you want to remove {{ $member->name ?? $member->mobile_number }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="inline-flex items-center px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 text-xs">
                                            <x-icon name="trash-2" class="w-3 h-3 mr-1" />
                                            Remove
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400 italic">You</span>
                                @endif
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                    <x-icon name="users" class="w-16 h-16 mx-auto text-gray-300 mb-4" />
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No staff members yet</h3>
                    <p class="text-gray-500 mb-4">Add your first employee to get started</p>
                    @can('staff.manage')
                        @if(!$atLimit)
                        <a href="{{ route('staff.create') }}" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                            Add Staff Member
                        </a>
                        @endif
                    @endcan
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
