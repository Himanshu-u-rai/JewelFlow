<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\MaskingStrategy as Mask;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition as Def;
use App\Services\Reporting\Definition\ReportFamily as Fam;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 contract audit (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.4).
 * Confirms every report family in the frozen matrix is representable by
 * ReportDefinition without architecture exceptions, and that the impossible
 * cases are rejected by the definition's invariants.
 */
class ReportDefinitionContractTest extends TestCase
{
    public function test_compliance_report_represents_rigidly(): void
    {
        $d = new Def(
            key: 'gstr1', version: 'gstr1@1', title: 'GSTR-1', classification: Cls::Compliance,
            columns: [Col::mandatory('invoice', 'Invoice', T::String), Col::mandatory('gstin', 'Buyer GSTIN', T::String), Col::mandatory('igst', 'IGST', T::Money)],
            profiles: [P::Fixed], filters: [Filter::for(FK::Period, true)], formats: [F::Pdf, F::Excel, F::Csv], permissions: Perm::default(),
        );
        $this->assertTrue($d->classification->isRigid());
        $this->assertFalse($d->hasSensitiveColumns());
    }

    public function test_accounting_with_ca_standard_and_formal_pdf(): void
    {
        $d = new Def(
            key: 'day-book', version: 'day-book@1', title: 'Day Book', classification: Cls::Accounting,
            columns: [Col::mandatory('dt', 'Date', T::DateTime), Col::mandatory('debit', 'Debit', T::Money), Col::sensitive('party', 'Party contact', T::String)],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard], filters: [Filter::for(FK::Period, true)], formats: [F::Pdf, F::Excel, F::Csv], permissions: Perm::default(),
        );
        $this->assertTrue($d->supportsProfile(P::CaStandard));
        $this->assertTrue($d->supportsFormat(F::Pdf));
    }

    public function test_owner_operational_receivables_represent(): void
    {
        $owner = new Def('pnl', 'pnl@1', 'P&L', Cls::Owner, [Col::mandatory('rev', 'Revenue', T::Money), Col::sensitive('cogs', 'COGS', T::Money)], [P::Summary, P::Detailed], [Filter::for(FK::Period, true)], [F::Pdf, F::Excel], Perm::default(), watermarkBaseline: 'CONFIDENTIAL');
        $operational = new Def('dead-stock', 'dead-stock@1', 'Dead Stock', Cls::Operational, [Col::mandatory('item', 'Item', T::String), Col::sensitive('cost', 'Cost', T::Money), Col::optional('cat', 'Category', T::String)], [P::Detailed], [Filter::for(FK::AsOf), Filter::for(FK::AgeBand)], [F::Excel, F::Csv, F::Pdf], Perm::default());
        $receivables = new Def('dues-aging', 'dues-aging@1', 'Customer Dues', Cls::Receivables, [Col::mandatory('customer', 'Customer', T::String), Col::sensitive('mobile', 'Mobile', T::String)], [P::Summary, P::Detailed, P::Ca], [Filter::for(FK::AsOf), Filter::for(FK::Customer)], [F::Pdf, F::Excel, F::Csv], Perm::default());

        $this->assertTrue($owner->hasSensitiveColumns());
        $this->assertTrue($operational->hasSensitiveColumns());
        $this->assertTrue($receivables->hasSensitiveColumns());
    }

    public function test_audit_report_carries_whole_surface_gate(): void
    {
        $d = new Def(
            key: 'operator-performance', version: 'operator-performance@1', title: 'Operator Performance', classification: Cls::Audit,
            columns: [Col::sensitive('operator', 'Operator', T::String), Col::mandatory('sales', 'Sales', T::Money)],
            profiles: [P::Detailed], filters: [Filter::for(FK::Period, true), Filter::for(FK::Operator)], formats: [F::Pdf, F::Excel, F::Csv],
            permissions: Perm::default()->withSurfaceGate('reports.audit'),
        );
        $this->assertSame('reports.audit', $d->permissions->effectiveViewGate());
    }

    public function test_dhiran_family_with_masking_bespoke_pdf_and_gates(): void
    {
        $d = new Def(
            key: 'dhiran-forfeiture', version: 'dhiran-forfeiture@1', title: 'Forfeiture', classification: Cls::Audit,
            columns: [Col::mandatory('loan', 'Loan no', T::String), Col::sensitive('aadhaar', 'Aadhaar', T::String, Mask::Mask), Col::sensitive('valuation', 'Valuation', T::Money)],
            profiles: [P::Detailed], filters: [Filter::for(FK::Period, true)], formats: [F::Pdf, F::Excel, F::Csv],
            permissions: Perm::default()->withFamilyGate('dhiran.reports')->withSurfaceGate('reports.audit'),
            family: Fam::Dhiran, pdfTemplate: 'reporting.dhiran.forfeiture-record', watermarkBaseline: 'CONFIDENTIAL',
        );
        $this->assertSame(Fam::Dhiran, $d->family);
        $this->assertTrue($d->hasMaskedColumns());
        $this->assertSame('reporting.dhiran.forfeiture-record', $d->pdfTemplate);
        $this->assertSame('dhiran.reports', $d->permissions->familyGate);
    }

    public function test_pilot_sales_register_with_reserved_branch_hook(): void
    {
        $d = new Def(
            key: 'sales-register', version: 'sales-register@1', title: 'Sales / Invoice Register', classification: Cls::Accounting,
            columns: [Col::mandatory('inv', 'Invoice', T::String), Col::mandatory('total', 'Total', T::Money), Col::sensitive('mobile', 'Mobile', T::String), Col::sensitive('operator', 'Operator', T::String), Col::sensitive('cost', 'Cost', T::Money)],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard, P::Raw],
            filters: [Filter::for(FK::Period, true), Filter::for(FK::Operator), Filter::for(FK::Status), Filter::for(FK::Branch)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen], permissions: Perm::default(),
        );
        $this->assertCount(4, $d->filters);
        $this->assertCount(3, $d->renderedFilters(), 'Branch is a reserved hook and must not render (frozen §3.2).');
        $this->assertTrue($d->supportsProfile(P::CaStandard));
    }

    public function test_compliance_rejects_optional_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Compliance, [Col::mandatory('a', 'A', T::Money), Col::optional('b', 'B', T::String)], [P::Fixed], [], [F::Pdf, F::Csv], Perm::default());
    }

    public function test_compliance_rejects_non_fixed_profile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Compliance, [Col::mandatory('a', 'A', T::Money)], [P::Summary], [], [F::Pdf, F::Csv], Perm::default());
    }

    public function test_accounting_rejects_missing_formal_pdf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Accounting, [Col::mandatory('a', 'A', T::Money)], [P::Detailed], [], [F::Excel, F::Csv], Perm::default());
    }

    public function test_operational_rejects_ca_standard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Operational, [Col::mandatory('a', 'A', T::Money)], [P::Detailed, P::CaStandard], [], [F::Excel, F::Pdf], Perm::default());
    }

    public function test_dhiran_family_requires_family_gate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Owner, [Col::mandatory('a', 'A', T::Money)], [P::Detailed], [], [F::Pdf, F::Excel], Perm::default(), family: Fam::Dhiran);
    }

    public function test_rejects_empty_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Def('bad', 'bad@1', 'bad', Cls::Owner, [], [P::Detailed], [], [F::Pdf], Perm::default());
    }
}
