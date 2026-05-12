<x-super-admin.layout>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-white">Backup Status</h2>
    </div>

    {{-- Overdue Warning --}}
    @if($isOverdue)
        <div class="mb-4 rounded-lg border border-amber-700 bg-amber-950 px-4 py-3 text-sm text-amber-200">
            <strong>Warning:</strong> No successful backup in the last 25 hours. Consider triggering a manual backup.
        </div>
    @endif

    {{-- Status Card --}}
    <div class="admin-panel p-4 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h3 class="font-semibold text-white mb-2">Last Backup</h3>
                @if($latestSuccess)
                    <div class="flex items-center gap-3">
                        <span class="admin-badge bg-emerald-900/60 text-emerald-300 border-emerald-700">Success</span>
                        <span class="text-sm text-slate-300">
                            {{ \Carbon\Carbon::parse($latestSuccess->completed_at)->format('d M Y, H:i') }}
                        </span>
                        @if($latestSuccess->filename)
                            <span class="text-xs text-slate-500 font-mono">{{ $latestSuccess->filename }}</span>
                        @endif
                        @if($latestSuccess->size_bytes)
                            @php
                                $b = $latestSuccess->size_bytes;
                                if ($b >= 1073741824) $sz = number_format($b/1073741824, 2).' GB';
                                elseif ($b >= 1048576) $sz = number_format($b/1048576, 2).' MB';
                                elseif ($b >= 1024) $sz = number_format($b/1024, 2).' KB';
                                else $sz = $b.' B';
                            @endphp
                            <span class="text-xs text-slate-400">{{ $sz }}</span>
                        @endif
                    </div>
                @else
                    <div class="flex items-center gap-3">
                        <span class="admin-badge bg-slate-800 text-slate-400 border-slate-700">None</span>
                        <span class="text-sm text-slate-500">No successful backup on record.</span>
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.backup.trigger') }}"
                  onsubmit="return confirm('Trigger a manual backup now? This runs in the background.')">
                @csrf
                <button type="submit" class="admin-btn admin-btn-primary">Trigger Manual Backup</button>
            </form>
        </div>
    </div>

    {{-- Backup Log Table --}}
    <div class="admin-panel overflow-hidden">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">Recent Backup Log</h3>
            <span class="text-xs text-slate-400">Last 20 entries</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Size</th>
                        <th class="px-4 py-2 text-left">Filename</th>
                        <th class="px-4 py-2 text-left">Completed At</th>
                        <th class="px-4 py-2 text-left">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 capitalize text-slate-300">{{ $log->type ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($log->status === 'success')
                                    <span class="admin-badge bg-emerald-900/60 text-emerald-300 border-emerald-700">Success</span>
                                @elseif($log->status === 'failed')
                                    <span class="admin-badge bg-rose-900/60 text-rose-300 border-rose-700">Failed</span>
                                @else
                                    <span class="admin-badge bg-slate-700 text-slate-300 border-slate-600">{{ ucfirst($log->status) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-xs text-slate-400">
                                @if($log->size_bytes)
                                    @php
                                        $b = $log->size_bytes;
                                        if ($b >= 1073741824) echo number_format($b/1073741824, 2).' GB';
                                        elseif ($b >= 1048576) echo number_format($b/1048576, 2).' MB';
                                        elseif ($b >= 1024) echo number_format($b/1024, 2).' KB';
                                        else echo $b.' B';
                                    @endphp
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ $log->filename ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">
                                {{ $log->completed_at ? \Carbon\Carbon::parse($log->completed_at)->format('d M Y, H:i') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate">{{ $log->notes ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No backup log entries yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
