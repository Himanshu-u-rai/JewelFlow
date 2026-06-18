<x-app-layout>
    <x-page-header class="loyalty-adjust-header" title="Adjust Points" subtitle="Manual loyalty balance correction">
        <x-slot:actions>
            <a href="{{ route('customers.show', $customer) }}" class="loyalty-adjust-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="loyalty-adjust-back-text">Back to customer</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner loyalty-adjust-page"
         x-data="{
             type: @js(old('type', 'earn')),
             points: @js(old('points', '')),
             current: {{ (int) $customer->loyalty_points }},
             projected() {
                 const amount = Number(this.points || 0);
                 return this.type === 'redeem' ? this.current - amount : this.current + amount;
             },
             format(value) {
                 return new Intl.NumberFormat('en-IN').format(value || 0);
             }
         }">
        <div class="loyalty-adjust-shell">
            <section class="loyalty-adjust-panel loyalty-adjust-form-panel">
                <div class="loyalty-adjust-panel-head">
                    <div>
                        <h2>Manual adjustment</h2>
                        <p>Choose the correction type, enter points, and record the reason for audit history.</p>
                    </div>
                    <div class="loyalty-adjust-balance-pill">
                        <span>Current balance</span>
                        <strong>{{ number_format($customer->loyalty_points) }}</strong>
                    </div>
                </div>

                <form method="POST" action="{{ route('loyalty.adjust', $customer) }}" class="loyalty-adjust-form">
                    @csrf

                    <div class="loyalty-adjust-field loyalty-adjust-field--full">
                        <span class="loyalty-adjust-label">Action</span>
                        <div class="loyalty-adjust-choice-grid">
                            <label class="loyalty-adjust-choice">
                                <input type="radio" name="type" value="earn" x-model="type" @checked(old('type', 'earn') === 'earn')>
                                <span class="loyalty-adjust-choice-body">
                                    <span class="loyalty-adjust-choice-title">Add points</span>
                                    <span class="loyalty-adjust-choice-copy">Use for bonus, correction, or goodwill credit.</span>
                                </span>
                            </label>
                            <label class="loyalty-adjust-choice">
                                <input type="radio" name="type" value="redeem" x-model="type" @checked(old('type', 'earn') === 'redeem')>
                                <span class="loyalty-adjust-choice-body">
                                    <span class="loyalty-adjust-choice-title">Deduct points</span>
                                    <span class="loyalty-adjust-choice-copy">Use for redemption correction or manual reversal.</span>
                                </span>
                            </label>
                        </div>
                        @error('type')<p class="loyalty-adjust-error">{{ $message }}</p>@enderror
                    </div>

                    <label for="points" class="loyalty-adjust-field">
                        <span class="loyalty-adjust-label">Points <strong>*</strong></span>
                        <input type="number"
                               name="points"
                               id="points"
                               value="{{ old('points') }}"
                               min="1"
                               required
                               x-model="points"
                               inputmode="numeric"
                               placeholder="Enter points">
                        @error('points')<p class="loyalty-adjust-error">{{ $message }}</p>@enderror
                    </label>

                    <label for="description" class="loyalty-adjust-field loyalty-adjust-field--full">
                        <span class="loyalty-adjust-label">Reason <strong>*</strong></span>
                        <textarea name="description"
                                  id="description"
                                  rows="3"
                                  maxlength="255"
                                  required
                                  placeholder="Example: Goodwill bonus, error correction, redemption reversal">{{ old('description') }}</textarea>
                        @error('description')<p class="loyalty-adjust-error">{{ $message }}</p>@enderror
                    </label>

                    <div class="loyalty-adjust-form-foot">
                        <a href="{{ route('customers.show', $customer) }}" class="loyalty-adjust-secondary">
                            Cancel
                        </a>
                        <button type="submit" class="loyalty-adjust-primary">
                            Adjust points
                        </button>
                    </div>
                </form>
            </section>

            <aside class="loyalty-adjust-panel loyalty-adjust-summary-panel">
                <div class="loyalty-adjust-customer">
                    <span>Customer</span>
                    <strong>{{ $customer->name }}</strong>
                    @if($customer->mobile)
                        <small>{{ $customer->mobile }}</small>
                    @endif
                </div>

                <div class="loyalty-adjust-summary-grid">
                    <div class="loyalty-adjust-summary-tile">
                        <span>Current</span>
                        <strong>{{ number_format($customer->loyalty_points) }}</strong>
                    </div>
                    <div class="loyalty-adjust-summary-tile" :class="type === 'redeem' ? 'is-deduct' : 'is-add'">
                        <span x-text="type === 'redeem' ? 'After deduction' : 'After addition'"></span>
                        <strong x-text="format(projected())"></strong>
                    </div>
                </div>

                <p class="loyalty-adjust-warning"
                   x-show="type === 'redeem' && Number(points || 0) > current"
                   x-cloak>
                    Deduction is higher than the current balance. Review before submitting.
                </p>

                <div class="loyalty-adjust-note">
                    <span>Audit note</span>
                    <p>The reason is stored with the loyalty transaction history and should explain why the manual adjustment was made.</p>
                </div>
            </aside>
        </div>
    </div>
</x-app-layout>
