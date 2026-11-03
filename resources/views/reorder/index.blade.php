@php
    $ruleCount = $rules->count();
    $activeRuleCount = $rules->where('is_active', true)->count();
    $alertCount = $alerts->count();
@endphp
<x-app-layout>
    <x-stock-workspace-header active="reorder">
        @can('inventory.edit')
            <a href="{{ route('reorder.create') }}" class="btn btn-primary btn-sm reorder-index-add">
                <x-icon name="plus" size="15" />
                <span class="reorder-index-add-full">Add Rule</span><span class="reorder-index-add-short">Add</span>
            </a>
        @endcan
    </x-stock-workspace-header>
    <div class="content-inner reorder-index-page stock-tool-page" data-list-workspace>
        @include('inventory.items.partials.navigation', ['placement' => 'body', 'active' => 'reorder'])
        <section class="stock-tool-board" data-list-frame aria-label="Reorder rules">
            <header class="stock-tool-header">
                <div>
                    <h2>Reorder rules</h2>
                    <dl class="stock-tool-stats">
                        <div><dt>Rules</dt><dd>{{ number_format($ruleCount) }}</dd></div>
                        <div><dt>Active</dt><dd>{{ number_format($activeRuleCount) }}</dd></div>
                        <div><dt>Alerts</dt><dd>{{ number_format($alertCount) }}</dd></div>
                    </dl>
                </div>
                <p class="stock-tool-muted">{{ $ruleCount - $activeRuleCount }} paused · thresholds apply to matching in-stock items</p>
            </header>
            <details class="stock-tool-alerts" open>
                <summary>Low stock watch <span class="stock-tool-status {{ $alertCount ? 'is-warning' : 'is-active' }}">{{ $alertCount ? $alertCount.' alerts' : 'All clear' }}</span><x-icon name="chevron-down" size="16" /></summary>
                @if($alertCount > 0)
                    <div class="stock-tool-alert-grid">
                        @foreach($alerts as $alert)
                            <article class="stock-tool-alert-row">
                                <div><h3>{{ $alert['category'] ?: 'All categories' }}</h3><p>{{ $alert['sub_category'] ?: 'All sub-categories' }} · {{ optional($alert['vendor'])->name ?: 'No preferred vendor linked' }}</p></div>
                                <dl class="stock-tool-stats">
                                    <div><dt>In stock</dt><dd>{{ $alert['current_stock'] }}</dd></div>
                                    <div><dt>Threshold</dt><dd>{{ $alert['threshold'] }}</dd></div>
                                    <div><dt>Short by</dt><dd>{{ max($alert['threshold'] - $alert['current_stock'], 0) }}</dd></div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="stock-tool-notice">All current stock levels are within the configured thresholds.</p>
                @endif
            </details>
            @if($ruleCount > 0)
                <div class="stock-tool-scroll" data-list-scroll tabindex="0" role="region" aria-label="Reorder rule records">
                    <table class="jf-table stock-tool-table">
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
                                    <th scope="row">{{ $rule->category ?: 'All categories' }}</th>
                                    <td>{{ $rule->sub_category ?: 'All sub-categories' }}</td>
                                    <td class="text-right">{{ $rule->min_stock_threshold }}</td>
                                    <td>{{ optional($rule->vendor)->name ?: 'Not linked' }}</td>
                                    <td><span class="stock-tool-status {{ $rule->is_active ? 'is-active' : '' }}">{{ $rule->is_active ? 'Active' : 'Paused' }}</span></td>
                                    <td><div class="jf-table-actions">
                                        @can('inventory.edit')
                                            <a href="{{ route('reorder.edit', $rule) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit rule"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit rule for {{ $rule->category ?: 'all categories' }}</span></a>
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
                    <x-icon name="package" size="28" />
                    <h3>No reorder rules yet</h3>
                    <p>Add your first rule to track stock thresholds by category or sub-category.</p>
                    @can('inventory.edit')<a href="{{ route('reorder.create') }}" class="btn btn-primary">Add first rule</a>@endcan
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
