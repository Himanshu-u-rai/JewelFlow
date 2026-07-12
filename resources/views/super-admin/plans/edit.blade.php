<x-super-admin.layout title="Plan Management" subtitle="Configure subscription products, billing cycles, and feature limits.">
    <div class="admin-form-shell">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-white">Edit Plan: {{ $plan->name }}</h3>
            <p class="text-sm text-slate-400">Update pricing and feature controls for this product.</p>
        </div>

        <div class="admin-panel p-6">
            <form action="{{ route('admin.plans.update', $plan) }}" method="POST">
                @method('PATCH')
                @include('super-admin.plans.form', ['plan' => $plan])
            </form>
        </div>
    </div>
</x-super-admin.layout>
