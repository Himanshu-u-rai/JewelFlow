<x-app-layout>
    <x-page-header title="Karigars" subtitle="Job-work artisans linked to this shop">
        <x-slot:actions>
            <a href="{{ route('karigars.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Karigar
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            @if($karigars->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <p class="text-sm mb-3">No karigars yet.</p>
                    <a href="{{ route('karigars.create') }}" class="text-teal-700 underline text-sm">Add your first karigar</a>
                </div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3 text-left font-semibold">Name</th>
                            <th class="px-4 py-3 text-left font-semibold">Mobile</th>
                            <th class="px-4 py-3 text-left font-semibold">GST</th>
                            <th class="px-4 py-3 text-left font-semibold">City</th>
                            <th class="px-4 py-3 text-right font-semibold">Job Orders</th>
                            <th class="px-4 py-3 text-right font-semibold">Invoices</th>
                            <th class="px-4 py-3 text-center font-semibold">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($karigars as $k)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('karigars.show', $k) }}'"  >
                                <td class="px-4 py-3">
                                    <a href="{{ route('karigars.show', $k) }}" class="text-teal-700 font-medium hover:underline">{{ $k->name }}</a>
                                    @if($k->contact_person)
                                        <div class="text-xs text-gray-400">{{ $k->contact_person }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $k->mobile ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500 text-xs font-mono">{{ $k->gst_number ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $k->city ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $k->job_orders_count }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $k->invoices_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($k->is_active)
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800">Active</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-200 text-gray-600">Disabled</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                                    <form method="POST" action="{{ route('karigars.toggle', $k) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="text-xs text-gray-500 hover:text-gray-800 mr-3">{{ $k->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <a href="{{ route('karigars.edit', $k) }}" class="text-xs text-teal-700 hover:underline">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
