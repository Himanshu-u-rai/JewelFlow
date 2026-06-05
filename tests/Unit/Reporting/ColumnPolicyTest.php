<?php

namespace Tests\Unit\Reporting;

use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The sensitive-column gate is the security boundary (frozen §7/§13/§28).
 * These prove a sensitive column reaches the output ONLY with permission + opt-in,
 * and never under CA Standard or Compliance.
 */
class ColumnPolicyTest extends TestCase
{
    private function accountingDef(): Def
    {
        return new Def(
            key: 'sales-register', version: 'sales-register@1', title: 'Sales Register', classification: Cls::Accounting,
            columns: [
                Col::mandatory('inv', 'Invoice', T::String),
                Col::mandatory('total', 'Total', T::Money),
                Col::optional('hsn', 'HSN', T::String),
                Col::optional('discount', 'Discount', T::Money),
                Col::sensitive('mobile', 'Mobile', T::String),
                Col::sensitive('cost', 'Cost', T::Money),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard, P::Raw],
            filters: [], formats: [F::Pdf, F::Excel, F::Csv], permissions: Perm::default(),
        );
    }

    private function user(bool $hasSensitive): User
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->with('reports.export_sensitive')->andReturn($hasSensitive);
        return $user;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sensitive_dropped_without_permission_even_when_opted_in(): void
    {
        $res = (new ColumnPolicy())->resolve($this->accountingDef(), P::Detailed, $this->user(false), includeSensitive: true);

        $this->assertNotContains('mobile', $res->columnKeys);
        $this->assertNotContains('cost', $res->columnKeys);
        $this->assertFalse($res->sensitiveIncluded);
    }

    public function test_sensitive_included_with_permission_and_optin(): void
    {
        $res = (new ColumnPolicy())->resolve($this->accountingDef(), P::Detailed, $this->user(true), includeSensitive: true);

        $this->assertContains('mobile', $res->columnKeys);
        $this->assertContains('cost', $res->columnKeys);
        $this->assertTrue($res->sensitiveIncluded);
    }

    public function test_sensitive_not_included_without_optin(): void
    {
        $res = (new ColumnPolicy())->resolve($this->accountingDef(), P::Detailed, $this->user(true), includeSensitive: false);

        $this->assertFalse($res->sensitiveIncluded);
    }

    public function test_ca_standard_never_includes_sensitive_even_with_permission_and_optin(): void
    {
        $res = (new ColumnPolicy())->resolve($this->accountingDef(), P::CaStandard, $this->user(true), includeSensitive: true);

        $this->assertNotContains('mobile', $res->columnKeys);
        $this->assertNotContains('cost', $res->columnKeys);
        $this->assertFalse($res->sensitiveIncluded);
        $this->assertContains('hsn', $res->columnKeys, 'CA Standard keeps the canonical optional set.');
    }

    public function test_summary_is_mandatory_only(): void
    {
        $res = (new ColumnPolicy())->resolve($this->accountingDef(), P::Summary, $this->user(true), includeSensitive: false);

        $this->assertSame(['inv', 'total'], $res->columnKeys);
    }

    public function test_user_optional_toggle_honoured_within_catalogue_only(): void
    {
        $res = (new ColumnPolicy())->resolve(
            $this->accountingDef(), P::Detailed, $this->user(false),
            includeSensitive: false, selectedOptional: ['hsn', 'not_a_real_column'],
        );

        $this->assertContains('hsn', $res->columnKeys);
        $this->assertNotContains('discount', $res->columnKeys);
        $this->assertNotContains('not_a_real_column', $res->columnKeys);
    }

    public function test_compliance_returns_full_fixed_catalogue_ignoring_everything(): void
    {
        $def = new Def(
            key: 'gstr1', version: 'gstr1@1', title: 'GSTR-1', classification: Cls::Compliance,
            columns: [Col::mandatory('invoice', 'Invoice', T::String), Col::mandatory('gstin', 'GSTIN', T::String), Col::mandatory('igst', 'IGST', T::Money)],
            profiles: [P::Fixed], filters: [], formats: [F::Pdf, F::Csv], permissions: Perm::default(),
        );

        $res = (new ColumnPolicy())->resolve($def, P::Fixed, $this->user(true), includeSensitive: true, selectedOptional: ['anything']);

        $this->assertSame(['invoice', 'gstin', 'igst'], $res->columnKeys);
        $this->assertFalse($res->sensitiveIncluded);
    }

    public function test_reveal_masked_requires_permission(): void
    {
        $withPerm = (new ColumnPolicy())->resolve($this->accountingDef(), P::Detailed, $this->user(true), includeSensitive: true, requestRevealMasked: true);
        $withoutPerm = (new ColumnPolicy())->resolve($this->accountingDef(), P::Detailed, $this->user(false), includeSensitive: true, requestRevealMasked: true);

        $this->assertTrue($withPerm->revealMasked);
        $this->assertFalse($withoutPerm->revealMasked);
    }
}
