<?php

namespace Tests\Feature\Tables;

use App\Models\Karigar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class JobOrderCreateUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_create_keeps_one_form_summary_before_submit_and_named_picker_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::create(['shop_id' => $shop->id, 'name' => 'Synthetic workshop with a long display name']));
        [, $otherShop] = $this->createRetailerTenant();
        TenantContext::runFor($otherShop->id, fn () => Karigar::create(['shop_id' => $otherShop->id, 'name' => 'Foreign private workshop']));
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.create', ['karigar' => $karigar->id])))->assertOk();
        $response->assertSee('Synthetic workshop with a long display name')->assertDontSee('Foreign private workshop');
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $form = '//form[@action="'.route('job-orders.store').'"]';
        $this->assertSame(1, $xp->query($form)->length);
        $this->assertSame(1, $xp->query($form.'//aside[@aria-labelledby="job-summary-title" and @tabindex="0"]')->length);
        $this->assertSame(1, $xp->query($form.'//aside/following-sibling::*//button[@type="submit"]')->length);
        $this->assertSame(4, $xp->query($form.'//*[@aria-label="Metal source"]/button[@type="button"]')->length);
        foreach (['karigar', 'metal', 'customer', 'mode', 'account'] as $name) {
            $this->assertSame(1, $xp->query($form.'//button[@aria-labelledby="job-'.$name.'-label job-'.$name.'-value"]')->length);
            $this->assertSame(1, $xp->query('//*[@id="job-'.$name.'-label"]')->length);
            $this->assertSame(1, $xp->query('//*[@id="job-'.$name.'-value"]')->length);
        }
        foreach (['karigar_id', 'metal_source', 'metal_type', 'purity', 'allowed_wastage_percent', 'issue_date', 'expected_return_date', 'notes', 'advance_amount', 'advance_mode', 'advance_payment_method_id'] as $name) {
            $this->assertSame(1, $xp->query($form.'//*[@name="'.$name.'"]')->length, $name);
        }
        $this->assertSame('25', $xp->evaluate('string('.$form.'//input[@name="allowed_wastage_percent"]/@max)'));
        $this->assertSame('0.01', $xp->evaluate('string('.$form.'//input[@name="advance_amount"]/@step)'));
        $this->assertSame(0, $xp->query($form.'//style')->length);
    }

    public function test_view_only_user_cannot_open_issue_form(): void
    {
        [$viewer, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($viewer, ['job_order.view']);
        $viewer->unsetRelation('role');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)->get(route('job-orders.create')))->assertForbidden();
    }
}
