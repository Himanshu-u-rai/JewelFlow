@php use App\Support\LegalInfo as L; @endphp
<x-legal-page title="Refund & Cancellation Policy" intro="How cancelling a JewelFlows plan works and when a payment is refunded.">
    <h2>Cancelling</h2>
    <p>You can cancel at any time by not renewing your plan, or by writing to us from the account owner's email or phone (see <a href="{{ route('legal.contact') }}">Contact Us</a>). Your plan stays active until the end of the period you have paid for. Your records stay available to export.</p>

    <h2>Refunds</h2>
    <p>Plan fees are paid in advance for the period you choose and are not refunded for a period already started, including when you cancel part-way through. We do refund:</p>
    <ul>
        <li>a payment charged twice for the same plan or period;</li>
        <li>a payment taken when the plan could not be activated, if we cannot fix the activation;</li>
        <li>any other amount we agree in writing to refund.</li>
    </ul>

    <h2>How refunds are made</h2>
    <p>Ask within 7 days of the payment, with the payment or invoice number. Approved refunds go back to the original payment method through Razorpay within 5 to 7 working days of approval; your bank may take a few more days to show it.</p>

    <h2>Questions</h2>
    <p>Email <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a> or call {!! L::field('phone', 'phone number') !!}.</p>
</x-legal-page>
