<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Restoration M17 (audit UX2): the invoice status badge compared status to
 * 'paid' — never an Invoice status — so every badge rendered amber. It now
 * colours by the real status (finalized=green, cancelled=red, draft=amber).
 */
class InvoiceStatusBadgeTest extends TestCase
{
    public function test_badge_no_longer_compares_to_paid(): void
    {
        $blade = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $this->assertStringNotContainsString("\$invoice->status == 'paid'", $blade);
        $this->assertStringContainsString('STATUS_FINALIZED => ', $blade);
        $this->assertStringContainsString('STATUS_CANCELLED => ', $blade);
    }
}
