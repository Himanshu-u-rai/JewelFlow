<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * One value from config/legal.php for the public policy pages. A value that has not been filled in renders
 * as a visible "to be added" note instead of an invented placeholder.
 */
final class LegalInfo
{
    public static function field(string $key, string $label): HtmlString
    {
        $value = $key === 'email' ? config('app.support_email') : config('legal.' . $key);
        if ($key === 'grievance_email' && blank($value)) {
            $value = config('app.support_email');
        }

        return blank($value)
            ? new HtmlString('<mark class="legal-missing">[' . e($label) . ': to be added]</mark>')
            : new HtmlString(e($value));
    }

    /** Pages in the footer and the page switcher, in reading order. */
    public static function pages(): array
    {
        return [
            'legal.about'    => 'About',
            'legal.contact'  => 'Contact',
            'legal.terms'    => 'Terms & Conditions',
            'legal.privacy'  => 'Privacy Policy',
            'legal.refund'   => 'Refund & Cancellation',
            'legal.delivery' => 'Service Delivery',
        ];
    }
}
