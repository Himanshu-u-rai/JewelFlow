@php use App\Support\LegalInfo as L; @endphp
<x-legal-page title="Privacy Policy" intro="What personal data JewelFlows collects, why, who processes it, and your rights.">
    <p>This policy explains how {{ config('legal.company') }} handles personal data in JewelFlows, under the Information Technology Act, 2000 and its rules and the Digital Personal Data Protection Act, 2023.</p>

    <h2>1. Two kinds of data</h2>
    <ul>
        <li><strong>Your account data</strong>: details about you, your shop and your staff. For this we decide how it is used.</li>
        <li><strong>Your business records</strong>: what you enter about your own customers, borrowers, karigars and vendors (names, phone numbers, addresses, PAN or other KYC details, photos, purchases, loans). You decide why this data is collected; we process it only on your instructions to run the service.</li>
    </ul>

    <h2>2. What we collect</h2>
    <ul>
        <li>Name, mobile number, email, shop name, address and GST details given at sign-up or in Settings.</li>
        <li>Login and security data: one-time verification codes where used, signed-in devices, IP address, and an audit log of important actions.</li>
        <li>Billing data: plan, invoices and payment status. Card and bank details go to Razorpay, not to us.</li>
        <li>Support messages you send us.</li>
    </ul>

    <h2>3. Why we use it</h2>
    <ul>
        <li>To provide, secure and support the service, including sending verification codes and service messages.</li>
        <li>To bill you and keep the tax and accounting records the law requires.</li>
        <li>To tell account owners about our products and offers, which you can switch off in Settings → Product preferences.</li>
    </ul>
    <p>We do not sell personal data and do not use your business records for advertising.</p>

    <h2>4. Who processes it for us</h2>
    <p>We share data only with service providers that help run JewelFlows, under contract and only for that purpose: our cloud hosting provider, Razorpay (payments), SMS and WhatsApp messaging providers (verification codes and messages), and email delivery. We disclose data to authorities only when the law requires it.</p>

    <h2>5. Where and how long</h2>
    <p>Data is stored on servers with encryption in transit, access controls and regular backups. We keep account and business records while your account is active, and afterwards as long as needed to meet legal obligations (for example tax records) or resolve disputes, then delete or anonymise them.</p>

    <h2>6. Your rights</h2>
    <p>You can ask to access, correct or erase your personal data, withdraw consent, and nominate someone to exercise these rights for you. Shop owners can export their records from the app. If you are a customer of a shop that uses JewelFlows, please contact that shop first, as it controls your data; we will help it respond.</p>

    <h2>7. Cookies</h2>
    <p>JewelFlows uses only the cookies needed to keep you signed in and protect forms. We do not use advertising or tracking cookies.</p>

    <h2>8. Children</h2>
    <p>JewelFlows is for businesses and is not meant for anyone under 18.</p>

    <h2>9. Changes</h2>
    <p>We will post updates here and change the date at the top. Significant changes will be notified to account owners.</p>

    <h2>10. Grievance officer</h2>
    <p>{!! L::field('grievance_officer', 'grievance officer name') !!}, {!! L::field('grievance_email', 'grievance email') !!}, {!! L::field('address', 'registered address') !!}. See <a href="{{ route('legal.contact') }}">Contact Us</a> for response times.</p>
</x-legal-page>
