<x-super-admin.layout>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-white">Edition Requests</h2>
            <p class="text-sm text-slate-400 mt-1">
                Shop-owner requests to add or remove a service. Approve → grants/revokes the edition and logs to audit.
            </p>
        </div>
        <div class="flex items-center gap-2">
            @foreach(['pending', 'approved', 'denied', 'cancelled'] as $tab)
                <a href="{{ route('admin.edition-requests.index', ['status' => $tab]) }}"
                   class="admin-btn {{ $status === $tab ? 'admin-btn-primary' : 'admin-btn-secondary' }} admin-btn-xs">
                    {{ ucfirst($tab) }}
                    @if($tab === 'pending' && $pendingCount > 0)
                        <span class="ml-1 inline-flex items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">{{ $pendingCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    @if(session('success'))
        <div class="mb-3 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-3 rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="admin-panel overflow-hidden">
        @if($requests->isEmpty())
            <div class="px-6 py-12 text-center text-slate-500 text-sm">
                No {{ $status }} requests.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm admin-table">
                    <thead class="bg-slate-800/80 text-slate-300">
                        <tr>
                            <th class="px-4 py-2 text-left">Shop</th>
                            <th class="px-4 py-2 text-left">Action</th>
                            <th class="px-4 py-2 text-left">Edition</th>
                            <th class="px-4 py-2 text-left">Requested by</th>
                            <th class="px-4 py-2 text-left">Reason</th>
                            <th class="px-4 py-2 text-left">Submitted</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requests as $req)
                            <tr class="border-t border-slate-800 text-slate-200 align-top">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.shops.show', $req->shop_id) }}" class="text-sky-300 hover:text-sky-200">
                                        {{ $req->shop?->name ?? '—' }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="admin-badge {{ $req->action === 'add' ? 'admin-badge-emerald' : 'admin-badge-amber' }}">
                                        {{ ucfirst($req->action) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-medium">{{ ucfirst($req->edition) }}</td>
                                <td class="px-4 py-3 text-slate-300">
                                    <div>{{ $req->user?->name ?? $req->user?->mobile_number ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $req->user?->mobile_number }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-300 max-w-md">{{ $req->reason }}</td>
                                <td class="px-4 py-3 text-xs text-slate-400">{{ $req->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($req->status === 'pending')
                                        <div class="flex flex-col gap-2 items-end">
                                            <details>
                                                <summary class="cursor-pointer text-xs text-emerald-300 hover:text-emerald-200">Approve →</summary>
                                                <form method="POST" action="{{ route('admin.edition-requests.approve', $req) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3 w-72">
                                                    @csrf
                                                    <label class="block text-xs text-slate-400">Notes (optional)</label>
                                                    <textarea name="review_notes" rows="2" maxlength="500" class="admin-control w-full" placeholder="Internal note for audit"></textarea>
                                                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs w-full">Approve & apply</button>
                                                </form>
                                            </details>
                                            <details>
                                                <summary class="cursor-pointer text-xs text-rose-300 hover:text-rose-200">Deny →</summary>
                                                <form method="POST" action="{{ route('admin.edition-requests.deny', $req) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3 w-72">
                                                    @csrf
                                                    <label class="block text-xs text-slate-400">Reason (required)</label>
                                                    <textarea name="review_notes" rows="2" required minlength="4" maxlength="500" class="admin-control w-full" placeholder="Shown to the shop owner"></textarea>
                                                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-xs w-full">Deny</button>
                                                </form>
                                            </details>
                                        </div>
                                    @else
                                        <div class="text-xs text-slate-400">
                                            <span class="admin-badge {{ match($req->status) { 'approved' => 'admin-badge-emerald', 'denied' => 'admin-badge-rose', default => 'admin-badge-slate' } }}">
                                                {{ ucfirst($req->status) }}
                                            </span>
                                            @if($req->reviewed_at)
                                                <div class="mt-1">{{ $req->reviewed_at->format('d M Y') }}</div>
                                            @endif
                                            @if($req->review_notes)
                                                <div class="mt-1 text-slate-300 italic text-[11px]">{{ $req->review_notes }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-slate-800">
                {{ $requests->links() }}
            </div>
        @endif
    </div>
</x-super-admin.layout>
