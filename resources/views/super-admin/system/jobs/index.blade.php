<x-super-admin.layout>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-kpi admin-tone-slate">
            <div class="text-xs text-slate-400">Queued Jobs</div>
            <div class="mt-1 text-2xl font-semibold text-white">{{ $jobs->count() }}</div>
        </div>
        <div class="admin-kpi admin-tone-rose">
            <div class="text-xs text-slate-400">Failed Jobs</div>
            <div class="mt-1 text-2xl font-semibold text-rose-200">{{ $failedJobs->count() }}</div>
        </div>
        <div class="admin-kpi admin-tone-amber">
            <div class="text-xs text-slate-400">Queues Monitored</div>
            <div class="mt-1 text-2xl font-semibold text-amber-200">{{ $jobs->pluck('queue')->unique()->count() }}</div>
        </div>
    </div>

    <div class="admin-panel mb-6">
        <div class="admin-panel-header">
            <h3 class="text-sm font-semibold text-white">Queued Jobs</h3>
            <p class="text-xs text-slate-400">Latest 50 queued or reserved jobs.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Job</th>
                        <th class="px-4 py-2 text-left">Queue</th>
                        <th class="px-4 py-2 text-center">Attempts</th>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-2">{{ $job['name'] }}</td>
                            <td class="px-4 py-2">{{ $job['queue'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $job['attempts'] }}</td>
                            <td class="px-4 py-2">
                                {{ $job['shop']['name'] ?? 'Unknown' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-slate-400">{{ $job['created_at'] ? \Carbon\Carbon::createFromTimestamp($job['created_at'])->toDateTimeString() : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-center text-slate-400">No queued jobs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3 class="text-sm font-semibold text-white">Failed Jobs</h3>
            <p class="text-xs text-slate-400">Latest 50 failed jobs. Retry carefully.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Job</th>
                        <th class="px-4 py-2 text-left">Queue</th>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Failed At</th>
                        <th class="px-4 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($failedJobs as $job)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-2">{{ $job['name'] }}</td>
                            <td class="px-4 py-2">{{ $job['queue'] }}</td>
                            <td class="px-4 py-2">{{ $job['shop']['name'] ?? 'Unknown' }}</td>
                            <td class="px-4 py-2 text-xs text-slate-400">{{ $job['failed_at'] }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.system.jobs.show', $job['id']) }}" class="admin-btn admin-btn-secondary admin-btn-xs">View Log</a>
                                <form method="POST" action="{{ route('admin.system.jobs.retry', $job['id']) }}" class="inline-flex">
                                    @csrf
                                    <button class="ml-2 admin-btn admin-btn-primary admin-btn-xs">Retry</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-center text-slate-400">No failed jobs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
