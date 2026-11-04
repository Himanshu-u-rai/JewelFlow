@php
    $ruleCount = $rules->count();
    $activeRuleCount = $rules->where('is_active', true)->count();
    $alertCount = $alerts->count();
@endphp
<x-app-layout>
    <x-stock-workspace-header active="reorder">
        @can('inventory.edit')
            <a href="{{ route('reorder.create') }}" class="btn btn-primary btn-sm reorder-index-add" aria-label="Add reorder rule" aria-haspopup="dialog" aria-controls="reorder-rule-dialog"
               x-data data-reorder-open @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey && $event.button === 0 && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) { $event.preventDefault(); $dispatch('reorder-rule-open'); }">
                <x-icon name="plus" size="15" />
                <span class="reorder-index-add-full stock-header-action-label">Add Rule</span><span class="reorder-index-add-short stock-header-action-label">Add</span>
            </a>
        @endcan
    </x-stock-workspace-header>
    <div class="content-inner reorder-index-page stock-tool-page">
        @include('inventory.items.partials.navigation', ['placement' => 'body', 'active' => 'reorder'])
        <section class="stock-tool-board" data-reorder-alerts aria-labelledby="reorder-alerts-title">
            <header class="stock-tool-header">
                <div>
                    <h2 id="reorder-alerts-title">Needs restocking <span class="stock-tool-status {{ $alertCount ? 'is-warning' : '' }}">{{ number_format($alertCount) }}</span></h2>
                    <p class="stock-tool-muted">Active rules below their minimum stock. Review these before placing an order.</p>
                </div>
            </header>
            @if($alertCount > 0)
                <div class="stock-tool-scroll" tabindex="0" role="region" aria-label="Stock shortages">
                    <table class="jf-table stock-tool-table reorder-alert-table">
                        <thead><tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col">Stock group</th>
                            <th scope="col" class="text-right">In stock</th>
                            <th scope="col" class="text-right">Minimum</th>
                            <th scope="col" class="text-right">Gap to minimum</th>
                            <th scope="col">Preferred vendor</th>
                        </tr></thead>
                        <tbody>
                        @foreach($alerts as $alert)
                            <tr>
                                <td class="jf-table-index">{{ $loop->iteration }}</td>
                                <th scope="row"><span class="reorder-alert-number" aria-hidden="true">{{ $loop->iteration }}.</span> {{ $alert['category'] ?: 'All categories' }}<span class="reorder-group-detail">{{ $alert['sub_category'] ?: 'All sub-categories' }}</span></th>
                                <td class="text-right" data-label="In stock">{{ $alert['current_stock'] }}</td>
                                <td class="text-right" data-label="Minimum">{{ $alert['threshold'] }}</td>
                                <td class="text-right" data-label="Gap to minimum"><strong class="reorder-shortage">{{ max($alert['threshold'] - $alert['current_stock'], 0) }}</strong></td>
                                <td data-label="Preferred vendor">{{ optional($alert['vendor'])->name ?: 'Not linked' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="stock-tool-notice">Each row represents one rule. Groups can overlap; do not add the gaps together as an order quantity.</p>
            @else
                <div class="reorder-state {{ $activeRuleCount ? 'is-clear' : '' }}">
                    <x-icon name="{{ $activeRuleCount ? 'circle-check' : 'settings' }}" size="24" />
                    <div>
                        @if($ruleCount === 0)
                            <h3>No reorder rules yet</h3>
                            <p>Add a rule to choose which stock groups to monitor and their minimum quantities.</p>
                        @elseif($activeRuleCount === 0)
                            <h3>All rules are paused</h3>
                            <p>Resume a rule below to monitor its stock level.</p>
                        @else
                            <h3>Stock is within your minimums</h3>
                            <p>No active rule is below its minimum stock level.</p>
                        @endif
                    </div>
                </div>
            @endif
        </section>
        <section id="reorder-rules" class="stock-tool-board reorder-rules" data-reorder-rules aria-labelledby="reorder-rules-title">
            <header class="stock-tool-header">
                <div>
                    <h2 id="reorder-rules-title">Alert rules</h2>
                    <p class="stock-tool-muted">{{ number_format($ruleCount) }} rules · {{ number_format($activeRuleCount) }} active · {{ number_format($ruleCount - $activeRuleCount) }} paused</p>
                </div>
            </header>
            <p class="stock-tool-notice">Set the minimum number of matching in-stock items. Edit a rule to change its group, vendor or active status.</p>
            @if($ruleCount > 0)
                <div class="stock-tool-scroll" data-list-scroll tabindex="0" role="region" aria-label="Reorder rule records">
                    <table class="jf-table stock-tool-table reorder-rules-table">
                        <thead><tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col">Category</th><th scope="col">Sub-Category</th>
                            <th scope="col" class="text-right">Min Threshold</th><th scope="col">Preferred Vendor</th>
                            <th scope="col">Status</th><th scope="col" class="text-right">Actions</th>
                        </tr></thead>
                        <tbody>
                            @foreach($rules as $rule)
                                <tr data-deletable-row>
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <th scope="row"><span class="reorder-alert-number" aria-hidden="true">{{ $loop->iteration }}.</span> {{ $rule->category ?: 'All categories' }}</th>
                                    <td data-label="Sub-category">{{ $rule->sub_category ?: 'All sub-categories' }}</td>
                                    <td class="text-right" data-label="Minimum">{{ $rule->min_stock_threshold }}</td>
                                    <td data-label="Preferred vendor">{{ optional($rule->vendor)->name ?: 'Not linked' }}</td>
                                    <td data-label="Status"><span class="stock-tool-status {{ $rule->is_active ? 'is-active' : '' }}">{{ $rule->is_active ? 'Active' : 'Paused' }}</span></td>
                                    <td data-label="Actions"><div class="jf-table-actions">
                                        @can('inventory.edit')
                                            <a href="{{ route('reorder.edit', $rule) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit rule"
                                               aria-haspopup="dialog" aria-controls="reorder-rule-dialog"
                                               x-data data-reorder-edit @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey && $event.button === 0 && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) { $event.preventDefault(); $dispatch('reorder-rule-open', {{ \Illuminate\Support\Js::from(['id' => $rule->id, 'action' => route('reorder.update', $rule), 'category' => $rule->category ?? '', 'sub_category' => $rule->sub_category ?? '', 'min_stock_threshold' => $rule->min_stock_threshold, 'vendor_id' => $rule->vendor_id ?? '', 'is_active' => $rule->is_active]) }}); }"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit rule for {{ $rule->category ?: 'all categories' }}</span></a>
                                            <form method="POST" action="{{ route('reorder.destroy', $rule) }}" data-confirm-message="Delete this reorder rule?" data-ajax-delete>
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="jf-row-action jf-row-action--danger" data-tip="Delete rule"><x-icon name="trash-2" size="16" /><span class="jf-sr-only">Delete rule for {{ $rule->category ?: 'all categories' }}</span></button>
                                            </form>
                                        @else
                                            <span class="stock-tool-muted">View only</span>
                                        @endcan
                                    </div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="stock-tool-empty">
                    <p>A rule monitors stock; it does not create a purchase automatically.</p>
                    @can('inventory.edit')<a href="{{ route('reorder.create') }}" class="btn btn-primary" aria-haspopup="dialog" aria-controls="reorder-rule-dialog" x-data data-reorder-open
                        @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey && $event.button === 0 && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) { $event.preventDefault(); $dispatch('reorder-rule-open'); }">Add first rule</a>@endcan
                </div>
            @endif
        </section>
    </div>
    @can('inventory.edit')
        @include('reorder.partials.rule-dialog')
    @endcan
</x-app-layout>
