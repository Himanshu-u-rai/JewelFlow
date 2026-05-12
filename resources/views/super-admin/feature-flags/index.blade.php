<x-super-admin.layout>
    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Feature Flags</h3>
            <p class="text-sm text-slate-400">Toggle platform features globally or per-shop.</p>
        </div>
    </div>

    {{-- Add / Update Flag Form --}}
    <div class="admin-panel p-5 mb-6">
        <h4 class="text-sm font-semibold text-slate-300 mb-4">Add / Update Flag</h4>
        <form method="POST" action="{{ route('admin.feature-flags.upsert') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="block text-sm mb-1 text-slate-300">Key <span class="text-rose-400">*</span></label>
                    <input type="text" name="key" value="{{ old('key') }}" placeholder="e.g. new_pos_ui"
                           class="admin-control w-full" required maxlength="100">
                </div>
                <div>
                    <label class="block text-sm mb-1 text-slate-300">Scope <span class="text-rose-400">*</span></label>
                    <select name="scope" class="admin-control admin-select w-full"
                            onchange="document.getElementById('scope_id_wrap').classList.toggle('hidden', this.value !== 'shop')">
                        <option value="global" @selected(old('scope', 'global') === 'global')>Global</option>
                        <option value="shop"   @selected(old('scope') === 'shop')>Shop Override</option>
                    </select>
                </div>
                <div id="scope_id_wrap" class="{{ old('scope') === 'shop' ? '' : 'hidden' }}">
                    <label class="block text-sm mb-1 text-slate-300">Shop ID</label>
                    <input type="number" name="scope_id" value="{{ old('scope_id') }}"
                           placeholder="Shop ID" min="1" class="admin-control w-full">
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm mb-1 text-slate-300">Description</label>
                    <input type="text" name="description" value="{{ old('description') }}"
                           placeholder="Short description" class="admin-control w-full" maxlength="500">
                </div>
                <div>
                    <label class="block text-sm mb-1 text-slate-300">Enabled <span class="text-rose-400">*</span></label>
                    <select name="enabled" class="admin-control admin-select w-full">
                        <option value="1" @selected((string) old('enabled', '1') === '1')>Yes — Enabled</option>
                        <option value="0" @selected((string) old('enabled') === '0')>No — Disabled</option>
                    </select>
                </div>
            </div>
            <div>
                <button type="submit" class="admin-btn admin-btn-primary">Save Flag</button>
            </div>
        </form>
    </div>

    {{-- Global Flags --}}
    <div class="admin-panel overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-slate-800">
            <h4 class="text-sm font-semibold text-slate-300">Global Flags</h4>
        </div>
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Key</th>
                        <th class="px-4 py-2 text-left">Enabled</th>
                        <th class="px-4 py-2 text-left">Description</th>
                        <th class="px-4 py-2 text-left">Last Updated</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($globalFlags as $flag)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 font-mono text-amber-300">{{ $flag->key }}</td>
                            <td class="px-4 py-3">
                                @if($flag->enabled)
                                    <span class="admin-badge admin-badge-emerald">Enabled</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Disabled</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400">{{ $flag->description ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-400 whitespace-nowrap">{{ $flag->updated_at?->diffForHumans() }}</td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('admin.feature-flags.destroy', $flag) }}"
                                      onsubmit="return confirm('Delete flag {{ $flag->key }}?')"
                                      class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="admin-btn admin-btn-rose admin-btn-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">No global flags configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Per-Shop Overrides --}}
    @if($shopOverrides->isNotEmpty())
    <div class="admin-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800">
            <h4 class="text-sm font-semibold text-slate-300">Per-Shop Overrides</h4>
        </div>
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Key</th>
                        <th class="px-4 py-2 text-left">Shop ID</th>
                        <th class="px-4 py-2 text-left">Enabled</th>
                        <th class="px-4 py-2 text-left">Description</th>
                        <th class="px-4 py-2 text-left">Last Updated</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shopOverrides as $key => $overrides)
                        @foreach($overrides as $flag)
                            <tr class="border-t border-slate-800 text-slate-200">
                                <td class="px-4 py-3 font-mono text-amber-300">{{ $flag->key }}</td>
                                <td class="px-4 py-3 text-slate-300">{{ $flag->scope_id }}</td>
                                <td class="px-4 py-3">
                                    @if($flag->enabled)
                                        <span class="admin-badge admin-badge-emerald">Enabled</span>
                                    @else
                                        <span class="admin-badge admin-badge-rose">Disabled</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-400">{{ $flag->description ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-400 whitespace-nowrap">{{ $flag->updated_at?->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.feature-flags.destroy', $flag) }}"
                                          onsubmit="return confirm('Delete override for {{ $flag->key }} / shop {{ $flag->scope_id }}?')"
                                          class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-btn admin-btn-rose admin-btn-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</x-super-admin.layout>
