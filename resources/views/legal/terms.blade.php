@php use App\Support\LegalInfo as L; @endphp
<x-legal-page title="Terms & Conditions" intro="The terms for using JewelFlows Retail and JewelFlows Dhiran.">
    <p>These terms are an agreement between you (the business that signs up, and everyone it invites) and {{ config('legal.company') }} ("we", "us"), the provider of JewelFlows. By creating an account or using JewelFlows you accept them.</p>

    <h2>1. The service</h2>
    <p>JewelFlows is online software for running a jewellery shop (Retail) or a gold loan business (Dhiran), with an Android companion app. Features depend on the plan you choose. We may improve or change features; we will not remove a core function you pay for during a paid period without notice.</p>

    <h2>2. Your account</h2>
    <ul>
        <li>You must be at least 18 and use JewelFlows for a lawful business.</li>
        <li>Give accurate details when you sign up and keep them up to date.</li>
        <li>Keep passwords and verification codes private. You are responsible for what happens under your account, including what staff you invite do with the access you give them.</li>
        <li>Tell us at once if you think your account has been misused.</li>
    </ul>

    <h2>3. Plans, fees and taxes</h2>
    <ul>
        <li>Plans are billed monthly or yearly, in advance, at the price shown when you pay. Prices include or add GST as shown on the invoice.</li>
        <li>Payments are processed by Razorpay. We do not store your card or bank details.</li>
        <li>If a plan ends and is not renewed, a short grace period may apply; after it, access may be limited until you pay. Your records are not deleted because a payment is late.</li>
        <li>Refunds and cancellations follow our <a href="{{ route('legal.refund') }}">Refund &amp; Cancellation Policy</a>.</li>
    </ul>

    <h2>4. Your data</h2>
    <p>The records you enter (stock, bills, customers, loans and so on) belong to you. We process them only to run the service for you, as described in our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>. You can export your data from the app. You are responsible for having a lawful basis, and any consent needed, for the personal data of your own customers that you record.</p>

    <h2>5. Your responsibilities in your trade</h2>
    <p>JewelFlows helps you keep records and calculate figures such as GST, metal value and interest. You remain responsible for your prices, tax filings, KYC, hallmarking, lending licences and every other legal duty of your business. Check figures before you rely on them.</p>

    <h2>6. Acceptable use</h2>
    <p>Do not misuse the service: no attempts to break in, probe other shops' data, overload the system, resell access without our written permission, or use it for fraud or any unlawful purpose. We may suspend an account that does.</p>

    <h2>7. Availability</h2>
    <p>We work to keep JewelFlows available and to back up data regularly, but the service may sometimes be interrupted for maintenance or reasons outside our control. We do not promise uninterrupted service.</p>

    <h2>8. Limitation of liability</h2>
    <p>To the extent the law allows, we are not liable for indirect or consequential loss, lost profit or lost business. Our total liability for any claim is limited to the fees you paid us in the 12 months before the claim.</p>

    <h2>9. Ending the agreement</h2>
    <p>You can stop using JewelFlows at any time. We may end or suspend access for a serious breach of these terms, with notice where practical. Export your data before your account closes.</p>

    <h2>10. Changes to these terms</h2>
    <p>We may update these terms. The date at the top shows the latest version; for significant changes we will tell account owners in the app or by email before they take effect.</p>

    <h2>11. Law and disputes</h2>
    <p>These terms are governed by the laws of India. Disputes are subject to the courts at {!! L::field('jurisdiction', 'city for courts') !!}.</p>

    <h2>12. Contact</h2>
    <p>Questions about these terms: <a href="{{ route('legal.contact') }}">Contact Us</a>.</p>
</x-legal-page>
