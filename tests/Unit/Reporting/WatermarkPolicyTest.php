<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\WatermarkPolicy;
use PHPUnit\Framework\TestCase;

class WatermarkPolicyTest extends TestCase
{
    private function def(Cls $class, ?string $baseline = null): Def
    {
        $profiles = $class->isRigid() ? [P::Fixed] : [P::Detailed];
        $formats = $class->requiresFormalPdf() ? [F::Pdf, F::Excel] : [F::Excel, F::Csv];
        return new Def('r', 'r@1', 'R', $class, [Col::mandatory('a', 'A', T::Money)], $profiles, [], $formats, Perm::default(), watermarkBaseline: $baseline);
    }

    public function test_compliance_never_watermarked(): void
    {
        $this->assertNull((new WatermarkPolicy())->for($this->def(Cls::Compliance), P::Fixed, sensitiveIncluded: true, isDraft: true));
    }

    public function test_ca_standard_profile_never_watermarked(): void
    {
        $this->assertNull((new WatermarkPolicy())->for($this->def(Cls::Accounting), P::CaStandard, sensitiveIncluded: true));
    }

    public function test_confidential_baseline(): void
    {
        $this->assertSame('CONFIDENTIAL', (new WatermarkPolicy())->for($this->def(Cls::Owner, 'CONFIDENTIAL'), P::Detailed));
    }

    public function test_audit_is_internal_and_confidential(): void
    {
        $label = (new WatermarkPolicy())->for($this->def(Cls::Audit), P::Detailed);
        $this->assertStringContainsString('INTERNAL USE ONLY', (string) $label);
        $this->assertStringContainsString('CONFIDENTIAL', (string) $label);
    }

    public function test_sensitive_optin_forces_confidential(): void
    {
        $this->assertSame('CONFIDENTIAL', (new WatermarkPolicy())->for($this->def(Cls::Accounting), P::Detailed, sensitiveIncluded: true));
    }

    public function test_plain_operational_has_no_watermark(): void
    {
        $this->assertNull((new WatermarkPolicy())->for($this->def(Cls::Operational), P::Detailed));
    }

    public function test_draft_state_labels_draft(): void
    {
        $this->assertStringContainsString('DRAFT', (string) (new WatermarkPolicy())->for($this->def(Cls::Accounting), P::Detailed, isDraft: true));
    }
}
