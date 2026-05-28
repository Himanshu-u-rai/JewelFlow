{{-- $dispositions: ordered collection of ReturnedItemDisposition --}}
@forelse($dispositions as $disp)
    <div class="flex items-start gap-2 text-sm {{ !$loop->last ? 'pb-2 border-b border-gray-100' : '' }}">
        <span class="text-gray-400 text-xs pt-0.5 min-w-[5rem]">
            {{ \Carbon\Carbon::parse($disp->dispositioned_at)->format('d M H:i') }}
        </span>
        <div>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                @if($disp->disposition === 'restocked') bg-green-100 text-green-800
                @elseif($disp->disposition === 'sent_to_melt') bg-orange-100 text-orange-800
                @elseif($disp->disposition === 'sent_to_rework') bg-blue-100 text-blue-800
                @elseif($disp->disposition === 'written_off') bg-red-100 text-red-800
                @else bg-gray-100 text-gray-700 @endif">
                {{ str_replace('_', ' ', ucfirst($disp->disposition)) }}
            </span>
            @if($disp->dispositionedBy)
                <span class="text-gray-500 ml-1">by {{ $disp->dispositionedBy->name }}</span>
            @endif
            @if($disp->notes)
                <p class="text-gray-500 text-xs mt-0.5">{{ $disp->notes }}</p>
            @endif
        </div>
    </div>
@empty
    <p class="text-sm text-gray-400 italic">No disposition recorded yet.</p>
@endforelse
