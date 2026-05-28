{{--
    Entity Timeline Component
    -------------------------
    Props:
      $feed         — LengthAwarePaginator from EntityEventService::feedFor()
      $title        — Card heading (default: 'Activity')
      $entityLabel  — Optional context label (e.g. 'Item', 'Invoice')

    Disclosure levels shown:
      Level 0 (operational) — amber dot, full opacity
      Level 1 (contextual)  — slate dot, slightly dimmed text
      Level 2 / 3           — not included in feedFor(maxLevel=1) — never shown here

    Design matches the inline timelines in inventory/items/show and invoices/show.
--}}
@if($feed->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    From {{ $feed->last()->occurred_at->format('d M Y') }}
                    @if($entityLabel) · {{ $entityLabel }}@endif
                </p>
            </div>
        </div>
        <ul class="divide-y divide-slate-100">
            @foreach($feed as $event)
            <li class="flex items-start gap-4 px-6 py-3">
                @if((int) $event->level === 0)
                    <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-amber-500"></span>
                @else
                    <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-slate-300"></span>
                @endif
                <div class="flex-1 min-w-0 {{ (int) $event->level > 0 ? 'opacity-80' : '' }}">
                    <p class="text-sm text-slate-800">{{ $event->summary }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $event->occurred_at->format('d M Y, g:i A') }}</p>
                </div>
            </li>
            @endforeach
        </ul>
        @if($feed->hasPages())
        <div class="px-6 py-4 border-t border-slate-100">
            {{ $feed->links() }}
        </div>
        @endif
    </div>
</div>
@endif
