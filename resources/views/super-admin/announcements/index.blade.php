<x-super-admin.layout>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-white">Platform Announcements</h2>
    </div>

    {{-- New / Edit Message Form --}}
    <div class="admin-panel p-4 mb-6">
        <h3 class="font-semibold text-white mb-4" id="ann-form-title">New Message</h3>
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-4" id="ann-form">
            @csrf
            <input type="hidden" name="_method" id="ann-method" value="POST">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Title <span class="text-rose-400">*</span></label>
                    <input type="text" name="title" id="ann-title" value="{{ old('title') }}" maxlength="255" required
                           class="admin-control w-full" placeholder="Message title / heading">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Type <span class="text-rose-400">*</span></label>
                        <select name="type" id="ann-type" required class="admin-control admin-select w-full">
                            <option value="banner"      {{ old('type') === 'banner'           ? 'selected' : '' }}>Banner (offers / deals)</option>
                            <option value="cross_promo" {{ old('type') === 'cross_promo'      ? 'selected' : '' }}>Cross-promo toast (override)</option>
                            <option value="info"        {{ old('type', 'info') === 'info'     ? 'selected' : '' }}>System: Info</option>
                            <option value="warning"     {{ old('type') === 'warning'          ? 'selected' : '' }}>System: Warning</option>
                            <option value="critical"    {{ old('type') === 'critical'         ? 'selected' : '' }}>System: Critical</option>
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div id="ann-target-value-wrap" style="{{ old('target', 'all') === 'all' ? 'display:none' : '' }}">
                    <label class="block text-xs text-slate-400 mb-1">Target Value</label>
                    <input type="text" name="target_value" id="ann-target-value" value="{{ old('target_value') }}" maxlength="100"
                           class="admin-control w-full" placeholder="e.g. retailer, manufacturer, plan-id">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Product surface</label>
                    <select name="realm" id="ann-realm" class="admin-control admin-select w-full">
                        <option value="" {{ old('realm') === null || old('realm') === '' ? 'selected' : '' }}>Both (ERP + Dhiran)</option>
                        <option value="erp"    {{ old('realm') === 'erp'    ? 'selected' : '' }}>ERP only</option>
                        <option value="dhiran" {{ old('realm') === 'dhiran' ? 'selected' : '' }}>Dhiran only</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Body <span class="text-rose-400">*</span></label>
                <textarea name="body" id="ann-body" rows="3" required
                          class="admin-control w-full" placeholder="Message text…">{{ old('body') }}</textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Button label <span class="text-slate-500">(optional)</span></label>
                    <input type="text" name="cta_label" id="ann-cta-label" value="{{ old('cta_label') }}" maxlength="80"
                           class="admin-control w-full" placeholder="e.g. View offer">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Button link <span class="text-slate-500">(optional, full URL)</span></label>
                    <input type="url" name="cta_url" id="ann-cta-url" value="{{ old('cta_url') }}" maxlength="2048"
                           class="admin-control w-full" placeholder="https://…">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Publish At <span class="text-slate-500">(blank = immediate)</span></label>
                    <input type="datetime-local" name="publish_at" id="ann-publish-at" value="{{ old('publish_at') }}"
                           class="admin-control w-full">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Expires At <span class="text-slate-500">(blank = never)</span></label>
                    <input type="datetime-local" name="expires_at" id="ann-expires-at" value="{{ old('expires_at') }}"
                           class="admin-control w-full">
                </div>
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" name="send_email" id="ann-send-email" value="1"
                       {{ old('send_email') ? 'checked' : '' }}
                       class="rounded border-slate-600 bg-slate-700 text-sky-500">
                <label for="ann-send-email" class="text-sm text-slate-300">Also send via email to all targeted users</label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="admin-btn admin-btn-primary" id="ann-submit">Save Message</button>
                <button type="button" class="admin-btn admin-btn-xs" id="ann-cancel-edit" style="display:none"
                        onclick="window.annResetForm()">Cancel edit</button>
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
                        <th class="px-4 py-2 text-left">Surface</th>
                        <th class="px-4 py-2 text-left">Publish At</th>
                        <th class="px-4 py-2 text-left">Expires At</th>
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
                                @elseif($ann->type === 'banner')
                                    <span class="admin-badge bg-emerald-900/60 text-emerald-300 border-emerald-700">Banner</span>
                                @elseif($ann->type === 'cross_promo')
                                    <span class="admin-badge bg-violet-900/60 text-violet-300 border-violet-700">Cross-promo</span>
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
                            <td class="px-4 py-3 text-xs text-slate-400">{{ $ann->realm ? ucfirst($ann->realm) : 'Both' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $ann->publish_at ? $ann->publish_at->format('d M Y, H:i') : '— immediate —' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $ann->expires_at ? $ann->expires_at->format('d M Y, H:i') : '— never —' }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button type="button" class="admin-btn admin-btn-xs"
                                        data-ann-edit
                                        data-ann='@json([
                                            "id" => $ann->id,
                                            "title" => $ann->title,
                                            "body" => $ann->body,
                                            "cta_label" => $ann->cta_label,
                                            "cta_url" => $ann->cta_url,
                                            "type" => $ann->type,
                                            "target" => $ann->target,
                                            "target_value" => $ann->target_value,
                                            "realm" => $ann->realm,
                                            "publish_at" => optional($ann->publish_at)->format("Y-m-d\TH:i"),
                                            "expires_at" => optional($ann->expires_at)->format("Y-m-d\TH:i"),
                                            "send_email" => (bool) $ann->send_email,
                                            "update_url" => route("admin.announcements.update", $ann),
                                        ])'>Edit</button>
                                <form method="POST" action="{{ route('admin.announcements.destroy', $ann) }}" class="inline"
                                      onsubmit="return confirm('Delete this message?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="admin-btn admin-btn-danger admin-btn-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">No messages yet.</td>
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

    <script>
        (function () {
            var form = document.getElementById('ann-form');
            var storeUrl = "{{ route('admin.announcements.store') }}";
            function setVal(id, v) { var el = document.getElementById(id); if (el) el.value = (v == null ? '' : v); }

            window.annResetForm = function () {
                form.setAttribute('action', storeUrl);
                document.getElementById('ann-method').value = 'POST';
                document.getElementById('ann-form-title').textContent = 'New Message';
                document.getElementById('ann-submit').textContent = 'Save Message';
                document.getElementById('ann-cancel-edit').style.display = 'none';
                form.reset();
                document.getElementById('ann-target-value-wrap').style.display =
                    (document.getElementById('ann-target').value === 'all') ? 'none' : 'block';
            };

            document.querySelectorAll('[data-ann-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var a = JSON.parse(btn.getAttribute('data-ann'));
                    form.setAttribute('action', a.update_url);
                    document.getElementById('ann-method').value = 'PUT';
                    document.getElementById('ann-form-title').textContent = 'Edit Message #' + a.id;
                    document.getElementById('ann-submit').textContent = 'Update Message';
                    document.getElementById('ann-cancel-edit').style.display = '';
                    setVal('ann-title', a.title); setVal('ann-body', a.body);
                    setVal('ann-cta-label', a.cta_label); setVal('ann-cta-url', a.cta_url);
                    setVal('ann-type', a.type); setVal('ann-target', a.target);
                    setVal('ann-target-value', a.target_value); setVal('ann-realm', a.realm || '');
                    setVal('ann-publish-at', a.publish_at); setVal('ann-expires-at', a.expires_at);
                    document.getElementById('ann-send-email').checked = !!a.send_email;
                    document.getElementById('ann-target-value-wrap').style.display = (a.target === 'all') ? 'none' : 'block';
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
        })();
    </script>
</x-super-admin.layout>
