<x-super-admin.layout>
    <div class="admin-form-shell">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-white">Create New Plan</h3>
            <p class="text-sm text-slate-400">Define pricing, due behavior, trial, and feature access.</p>
        </div>

        <div class="admin-panel p-6">
            <form action="{{ route('admin.plans.store') }}" method="POST">
                @include('super-admin.plans.form')
            </form>
        </div>
    </div>
</x-super-admin.layout>
