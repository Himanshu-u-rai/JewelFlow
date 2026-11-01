<div class="customers-mobile-cards customers-mobile-cards--occasions">
    @forelse($occasions as $occasion)
        <article class="customers-mobile-card">
            <div class="customers-mobile-card__top">
                <div>
                    <span class="customers-mobile-card__title">{{ $occasion['customer_name'] }}</span>
                    <span class="customers-mobile-card__sub">{{ $occasion['mobile'] ?: 'No mobile' }}</span>
                </div>
                <span class="customers-mobile-pill">{{ $occasion['type'] }}</span>
            </div>

            <div class="customers-mobile-card__metrics">
                <div>
                    <span>Date</span>
                    <strong>{{ \Carbon\Carbon::parse($occasion['date'])->format('d M Y') }}</strong>
                </div>
                <div>
                    <span>Due in</span>
                    <strong>{{ (int) $occasion['days_until'] === 0 ? 'Today' : ((int) $occasion['days_until'] . ' days') }}</strong>
                </div>
            </div>

            <div class="customers-mobile-card__actions">
                <a href="{{ route('customers.show', $occasion['customer_id']) }}" class="customers-row-action customers-row-action--primary">View Profile</a>
            </div>
        </article>
    @empty
        <div class="customers-mobile-empty">
            <strong>No occasions in this window</strong>
            <span>Increase the reminder window or add occasion dates on customer profiles.</span>
        </div>
    @endforelse
</div>
