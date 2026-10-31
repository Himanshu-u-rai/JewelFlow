<x-dhiran-layout title="Choose your Dhiran plan">
    <div class="dop-wrap">
        <div class="dop-head">
            <h1>Start your Dhiran subscription</h1>
            <p>Run your pledge loan business with confidence.</p>
        </div>

        <div class="dop-card">
            <div class="dop-card-top">
                <span class="dop-badge">Yearly plan</span>
                <p class="dop-plan-name">{{ $plan->name }}</p>
                <div class="dop-price">
                    <span class="amt">₹{{ number_format((float) $plan->price_yearly, 0) }}</span>
                    <span class="per">per year</span>
                </div>
            </div>

            <ul class="dop-feat">
                <li>
                    <x-icon name="check" stroke-width="2.4" />
                    Track every pledge loan, interest, and repayment in one place.
                </li>
                <li>
                    <x-icon name="check" stroke-width="2.4" />
                    Printable loan receipts and closure certificates.
                </li>
                <li>
                    <x-icon name="check" stroke-width="2.4" />
                    Renewal, pre-close, and forfeiture handling built in.
                </li>
                <li>
                    <x-icon name="check" stroke-width="2.4" />
                    Reports for the whole year, ready when you need them.
                </li>
            </ul>

            <div class="dop-foot">
                <form method="POST" action="{{ route('dhiran.subscribe') }}" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="dop-btn">Continue to payment</button>
                </form>
                <p class="dop-note">You pay for one year. Set up your business right after payment.</p>
            </div>
        </div>
    </div>
</x-dhiran-layout>
