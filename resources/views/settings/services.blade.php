@extends('layouts.app')

@section('title', __('Services & Editions'))

@section('content')
<div class="p-6 max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('Services & Editions') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            {{ __('Manage the services enabled for your shop. Add a new service or remove one you no longer need.') }}
        </p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Active services --}}
    <section class="mb-8">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-3">{{ __('Active Services') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach($active as $ed)
                @php
                    $meta = [
                        'retailer'     => ['label' => 'Retailer',     'tint' => 'amber'],
                        'manufacturer' => ['label' => 'Manufacturer', 'tint' => 'indigo'],
                        'dhiran'       => ['label' => 'Dhiran',       'tint' => 'teal'],
                    ][$ed] ?? ['label' => ucfirst($ed), 'tint' => 'gray'];
                    $assignment = $assignments[$ed] ?? null;
                @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between mb-2">
                        <div>
                            <div class="text-base font-semibold text-gray-900">{{ $meta['label'] }}</div>
                            <div class="text-xs text-emerald-600 font-medium mt-0.5">● {{ __('Active') }}</div>
                        </div>
                    </div>
                    @if($assignment?->activated_at)
                        <div class="text-xs text-gray-500 mb-3">
                            {{ __('Since') }} {{ $assignment->activated_at->format('d M Y') }}
                        </div>
                    @endif

                    @if(count($active) > 1)
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs text-rose-600 hover:text-rose-700 font-medium">{{ __('Remove this service →') }}</summary>
                            <form method="POST" action="{{ route('settings.services.remove') }}" class="mt-3 space-y-2">
                                @csrf
                                <input type="hidden" name="edition" value="{{ $ed }}">
                                <label class="block text-xs text-gray-600">{{ __('Why are you removing this?') }}</label>
                                <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                                          placeholder="{{ __('e.g. We are no longer offering gold loans.') }}"></textarea>
                                <label class="flex items-start gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5">
                                    <span>{{ __('I understand this removes access to this service.') }}</span>
                                </label>
                                <button type="submit" class="rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">
                                    {{ __('Remove ') }} {{ $meta['label'] }}
                                </button>
                            </form>
                        </details>
                    @else
                        <div class="text-xs text-gray-400 mt-3">
                            {{ __('This is your only active service. To cancel, contact support.') }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Available to add --}}
    @if(count($available) > 0)
        <section class="mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-3">{{ __('Add a Service') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach($available as $ed)
                    @php
                        $meta = [
                            'retailer'     => ['label' => 'Retailer', 'desc' => 'Buy & sell ready-made jewellery with POS.'],
                            'manufacturer' => ['label' => 'Manufacturer', 'desc' => 'Make jewellery in-house with lots & wastage.'],
                            'dhiran'       => ['label' => 'Dhiran', 'desc' => 'Gold loans on pledge.'],
                        ][$ed] ?? ['label' => ucfirst($ed), 'desc' => ''];

                        $pending = $pendingRequests->firstWhere(fn($r) => $r->edition === $ed && $r->action === 'add');
                    @endphp

                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-5">
                        <div class="text-base font-semibold text-gray-900">{{ $meta['label'] }}</div>
                        <div class="text-xs text-gray-500 mt-1 mb-3">{{ __($meta['desc']) }}</div>

                        @if($pending)
                            <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1.5 mb-2">
                                {{ __('Request pending review since') }} {{ $pending->created_at->format('d M Y') }}
                            </div>
                            <form method="POST" action="{{ route('settings.services.request.cancel', $pending) }}">
                                @csrf
                                <button type="submit" class="text-xs text-gray-500 hover:text-gray-700 underline">{{ __('Cancel request') }}</button>
                            </form>
                        @else
                            <details>
                                <summary class="cursor-pointer inline-flex items-center gap-1 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                                    {{ __('Request to add') }} →
                                </summary>
                                <form method="POST" action="{{ route('settings.services.request-add') }}" class="mt-3 space-y-2">
                                    @csrf
                                    <input type="hidden" name="edition" value="{{ $ed }}">
                                    <label class="block text-xs text-gray-600">{{ __('Why do you want this service?') }}</label>
                                    <textarea name="reason" rows="2" required minlength="10" maxlength="500"
                                              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                                              placeholder="{{ __('A short reason helps us prepare your upgrade.') }}"></textarea>
                                    <button type="submit" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                                        {{ __('Submit Request') }}
                                    </button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Pending requests (non-add) --}}
    @php
        $pendingRemove = $pendingRequests->where('action', 'remove');
    @endphp
    @if($pendingRemove->isNotEmpty())
        <section class="mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-3">{{ __('Pending Removal Requests') }}</h2>
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Service</th>
                            <th class="px-4 py-2 text-left">Submitted</th>
                            <th class="px-4 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingRemove as $req)
                            <tr class="border-t border-gray-100">
                                <td class="px-4 py-2 font-medium text-gray-800">{{ ucfirst($req->edition) }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $req->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-2 text-right">
                                    <form method="POST" action="{{ route('settings.services.request.cancel', $req) }}">
                                        @csrf
                                        <button type="submit" class="text-xs text-gray-500 hover:text-gray-700 underline">{{ __('Cancel') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Recent history --}}
    @if($history->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 mb-3">{{ __('Recent Activity') }}</h2>
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Request</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">Reviewed</th>
                            <th class="px-4 py-2 text-left">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $req)
                            <tr class="border-t border-gray-100">
                                <td class="px-4 py-2">{{ ucfirst($req->action) }} {{ ucfirst($req->edition) }}</td>
                                <td class="px-4 py-2">
                                    @php
                                        $cls = match($req->status) {
                                            'approved'  => 'text-emerald-700 bg-emerald-50',
                                            'denied'    => 'text-rose-700 bg-rose-50',
                                            'cancelled' => 'text-gray-600 bg-gray-100',
                                            default     => 'text-amber-700 bg-amber-50',
                                        };
                                    @endphp
                                    <span class="text-xs font-medium px-2 py-0.5 rounded {{ $cls }}">{{ ucfirst($req->status) }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ $req->reviewed_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600 text-xs">{{ $req->review_notes ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection
