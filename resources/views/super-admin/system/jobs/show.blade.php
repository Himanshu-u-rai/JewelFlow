<x-super-admin.layout>
    <div class="mb-4">
        <a href="{{ route('admin.system.jobs.index') }}" class="admin-btn admin-btn-secondary">Back to Jobs</a>
    </div>

    <div class="admin-panel p-4 mb-6">
        <h3 class="text-lg font-semibold text-white">{{ $job['name'] }}</h3>
        <div class="mt-2 text-sm text-slate-400">Queue: {{ $job['queue'] }} • Failed at {{ $job['failed_at'] }}</div>
        <div class="mt-2 text-sm text-slate-400">Shop: {{ $job['shop']['name'] ?? 'Unknown' }}</div>
    </div>

    <div class="admin-panel p-4">
        <h4 class="text-sm font-semibold text-white mb-2">Exception</h4>
        <pre class="whitespace-pre-wrap text-xs text-rose-200 bg-slate-950/60 border border-slate-800 rounded-lg p-3 overflow-x-auto">{{ $job['exception'] }}</pre>
    </div>
</x-super-admin.layout>
