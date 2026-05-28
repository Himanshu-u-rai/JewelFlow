{{-- $breakdown: decoded array from return_line_items.policy_breakdown --}}
{{-- $lineId: unique key for the details element --}}
@if(is_null($breakdown))
    <span class="text-xs text-gray-400 italic">Legacy return — breakdown not recorded.</span>
@else
<details class="text-xs mt-1" id="breakdown-{{ $lineId }}">
    <summary class="cursor-pointer text-indigo-600 hover:text-indigo-800 select-none">
        View deduction breakdown
    </summary>
    <dl class="mt-2 space-y-1 bg-gray-50 rounded p-3 border border-gray-200">
        <div class="flex justify-between">
            <dt class="text-gray-500">Original paid</dt>
            <dd class="font-medium">₹{{ number_format($breakdown['original_line_total'] ?? 0, 2) }}</dd>
        </div>
        @if(($breakdown['making_retained'] ?? 0) > 0)
        <div class="flex justify-between text-red-700">
            <dt>− Making charges retained</dt>
            <dd>₹{{ number_format($breakdown['making_retained'], 2) }} <span class="text-gray-400">(policy)</span></dd>
        </div>
        @endif
        @if(($breakdown['stone_retained'] ?? 0) > 0)
        <div class="flex justify-between text-red-700">
            <dt>− Stone charges retained</dt>
            <dd>₹{{ number_format($breakdown['stone_retained'], 2) }}</dd>
        </div>
        @endif
        @php
            $gstNotRefunded = ($breakdown['gst_charged'] ?? 0) - ($breakdown['gst_refunded'] ?? 0);
        @endphp
        @if($gstNotRefunded > 0.005)
        <div class="flex justify-between text-red-700">
            <dt>− GST not refunded</dt>
            <dd>₹{{ number_format($gstNotRefunded, 2) }}</dd>
        </div>
        @endif
        @if(($breakdown['wear_loss_amount'] ?? 0) > 0)
        <div class="flex justify-between text-red-700">
            <dt>− Wear loss ({{ $breakdown['wear_loss_pct'] ?? 0 }}%)</dt>
            <dd>₹{{ number_format($breakdown['wear_loss_amount'], 2) }}</dd>
        </div>
        @endif
        @if(($breakdown['restocking_fee_amount'] ?? 0) > 0)
        <div class="flex justify-between text-red-700">
            <dt>− Restocking fee ({{ $breakdown['restocking_fee_pct'] ?? 0 }}%)</dt>
            <dd>₹{{ number_format($breakdown['restocking_fee_amount'], 2) }}</dd>
        </div>
        @endif
        <div class="flex justify-between font-semibold border-t border-gray-300 pt-1 mt-1">
            <dt class="text-gray-700">= Final refund</dt>
            <dd class="text-green-700">₹{{ number_format($breakdown['final_refund_total'] ?? 0, 2) }}</dd>
        </div>
        @if(isset($breakdown['policy_at_settle']['configured_at']))
        <div class="text-gray-400 pt-1 text-[10px]">Policy as of: {{ $breakdown['policy_at_settle']['configured_at'] }}</div>
        @endif
    </dl>
</details>
@if(($breakdown['override_applied'] ?? false))
<div class="mt-2 rounded border border-indigo-200 bg-indigo-50 p-3 text-xs">
    <div class="font-semibold text-indigo-800 mb-1">
        Override applied — {{ $breakdown['override_mode'] ?? 'unknown' }} mode
    </div>
    <div class="text-indigo-700 mb-1">
        Reason: "{{ $breakdown['override_reason'] ?? '' }}"
    </div>
    @if(isset($breakdown['original_policy_result']) && isset($breakdown['negotiated_result']))
    <div class="flex gap-6 mt-1">
        <div>
            <span class="text-indigo-500">Original policy:</span>
            <span class="font-medium text-indigo-800">₹{{ number_format($breakdown['original_policy_result']['refund_total'] ?? 0, 2) }}</span>
        </div>
        <div>
            <span class="text-indigo-500">Negotiated:</span>
            <span class="font-medium text-indigo-800">₹{{ number_format($breakdown['negotiated_result']['refund_total'] ?? 0, 2) }}</span>
        </div>
    </div>
    @endif
</div>
@endif
@endif
