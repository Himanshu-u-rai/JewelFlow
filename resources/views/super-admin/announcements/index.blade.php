<x-super-admin.layout>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-white">Platform Announcements</h2>
    </div>

    {{-- New Announcement Form --}}
    <div class="admin-panel p-4 mb-6">
        <h3 class="font-semibold text-white mb-4">New Announcement</h3>
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Title <span class="text-rose-400">*</span></label>
                    <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required
                           class="admin-control w-full" placeholder="Announcement title">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Type <span class="text-rose-400">*</span></label>
                        <select name="type" required class="admin-control admin-select w-full">
                            <option value="info"     {{ old('type', 'info') === 'info'     ? 'selected' : '' }}>Info</option>
                            <option value="warning"  {{ old('type') === 'warning'          ? 'selected' : '' }}>Warning</option>
                            <option value="critical" {{ old('type') === 'critical'         ? 'selected' : '' }}>Critical</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Target <span class="text-rose-400">*</span></label>
                        <select name="target" id="ann-target" required class="admin-control admin-select w-full"
                                onchange="document.getElementById('ann-target-value-wrap').style.display = this.value === 'all' ? 'none' : 'block'">
                            <option value="all"     {{ old('target', 'all') === 'all'     ? 'selected' : '' }}>All Shops</option>
                            <option value="plan"    {{ old('target') === 'plan'           ? 'selected' : '' }}>Plan</option>
                            <option value="edition" {{ old('target') === 'edition'        ? 'selected' : '' }}>Edition</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="ann-target-value-wrap" style="{{ old('target', 'all') === 'all' ? 'display:none' : '' }}">
                <label class="block text-xs text-slate-400 mb-1">Target Value</label>
                <input type="text" name="target_value" value="{{ old('target_value') }}" maxlength="100"
                       class="admin-control w-full md:w-1/2" placeholder="e.g. retailer, manufacturer, plan-id">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Body <span class="text-rose-400">*</span></label>
                <textarea name="body" rows="3" required
                          class="admin-control w-full" placeholder="Announcement message…">{{ old('body') }}</textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Publish At <span class="text-slate-500">(blank = immediate)</span></label>
                    <input type="datetime-local" name="publish_at" value="{{ old('publish_at') }}"
                           class="admin-control w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Expires At <span class="text-slate-500">(blank = never)</span></label>
                    <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"
                           class="admin-control w-full">
                </div>
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" name="send_email" id="ann-send-email" value="1"
                       {{ old('send_email') ? 'checked' : '' }}
                       class="rounded border-slate-600 bg-slate-700 text-sky-500">
                <label for="ann-send-email" class="text-sm text-slate-300">Also send via email to all targeted users</label>
            </div>

            <div>
                <button type="submit" class="admin-btn admin-btn-primary">Save Announcement</button>
            </div>
        </form>
    </div>

    {{-- Announcements Table --}}
    <div class="admin-panel overflow-hidden">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">All Announcements</h3>
            <span class="text-xs text-slate-400">{{ $announcements->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Title</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Target</th>
                        <th class="px-4 py-2 text-left">Publish At</th>
                        <th class="px-4 py-2 text-left">Expires At</th>
                        <th class="px-4 py-2 text-left">Created At</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($announcements as $ann)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 font-medium max-w-xs truncate">{{ $ann->title }}</td>
                            <td class="px-4 py-3">
                                @if($ann->type === 'critical')
                                    <span class="admin-badge bg-rose-900/60 text-rose-300 border-rose-700">Critical</span>
                                @elseif($ann->type === 'warning')
                                    <span class="admin-badge bg-amber-900/60 text-amber-300 border-amber-700">Warning</span>
                                @else
                                    <span class="admin-badge bg-blue-900/60 text-blue-300 border-blue-700">Info</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                {{ ucfirst($ann->target) }}
                                @if($ann->target_value)
                                    <span class="text-xs text-slate-500">({{ $ann->target_value }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $ann->publish_at ? $ann->publish_at->format('d M Y, H:i') : '— immediate —' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $ann->expires_at ? $ann->expires_at->format('d M Y, H:i') : '— never —' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $ann->created_at->format('d M Y, H:i') }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('admin.announcements.destroy', $ann) }}"
                                      onsubmit="return confirm('Delete this announcement?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-btn admin-btn-danger admin-btn-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">No announcements yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($announcements->hasPages())
            <div class="px-4 py-3 border-t border-slate-800">
                {{ $announcements->links() }}
            </div>
        @endif
    </div>
</x-super-admin.layout>
