<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Restoration M11 (audit R1/R2): the rework job-work backend was never built
 * (commit 3d22ed5), so the live "Send to Karigar" disposition only trapped
 * items in an unclearable queue and contradicted the retired "Rework (manual)"
 * affordance. Rework is RETIRED with a documented replacement path
 * (melt → vault → karigar job). These guard against the dead option creeping back.
 */
class ReworkRetirementTest extends TestCase
{
    public function test_send_to_karigar_button_is_removed_from_control_center(): void
    {
        $blade = file_get_contents(resource_path('views/returns/control-center.blade.php'));
        $this->assertStringNotContainsString('value="sent_to_rework"', $blade,
            'the live Send-to-Karigar disposition button must be gone');
        $this->assertStringNotContainsString('>Send to Karigar<', $blade);
    }

    public function test_redispose_and_create_no_longer_accept_sent_to_rework(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Returns/ReturnsController.php'));
        // The constant still appears for DISPLAYING legacy/history rows (Queue 3),
        // but it must no longer be a comma-terminated entry in either Rule::in
        // disposition allow-list (the only place it was an accepted input).
        $this->assertStringNotContainsString('DISPOSITION_SENT_TO_REWORK,', $src,
            'sent_to_rework must be removed from the disposition validation allow-lists');
    }

    public function test_replacement_path_is_documented_for_operators(): void
    {
        $blade = file_get_contents(resource_path('views/returns/control-center.blade.php'));
        $this->assertStringContainsString('Send to Melt', $blade);
        $this->assertMatchesRegularExpression('/melt.*vault.*karigar|Send to Melt.*recover/is', $blade,
            'operators must be told the melt → vault → karigar-job replacement path');
    }
}
