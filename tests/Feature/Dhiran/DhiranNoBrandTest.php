<?php

namespace Tests\Feature\Dhiran;

use App\Mail\EmailOtpMail;
use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Owner 2026-11-12: Dhiran never says "JewelFlows" (screens, sign-in pages, emails), and the daily New Loan page
 * carries no old-loan entry; paper-book loans have their own module.
 */
class DhiranNoBrandTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dhiran.jewelflows.com';

    public function test_dhiran_pages_and_emails_never_say_jewelflows(): void
    {
        $sources = collect(File::allFiles(resource_path('views/dhiran')))->merge(File::allFiles(resource_path('views/components/dhiran')))
            ->filter(fn ($f) => preg_match('/jewel\s?flows/i', preg_replace('/\{\{--.*?--\}\}/s', '', $f->getContents())))
            ->map(fn ($f) => $f->getRelativePathname())->values()->all();
        $this->assertSame([], $sources, 'Dhiran views mentioning the brand');

        foreach (['/login', '/register', '/forgot-password'] as $page) {
            $html = $this->get(self::HOST . $page)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/jewel\s?flows/i', strip_tags($html), "Dhiran {$page}");
        }
        $this->assertStringContainsString('JewelFlows', strip_tags($this->get('https://jewelflows.com/login')->getContent()), 'retail sign-in unchanged');

        $card = Blade::render('<x-cross-promo-card heading="h" body="b" cta="c" url="#" realm="dhiran" />');
        // Visible words and the screen-reader label; the web address (dhiran.jewelflows.com) is not wording.
        $this->assertDoesNotMatchRegularExpression('/jewel\s?flows/i', strip_tags($card) . implode(' ', preg_match_all('/aria-label="([^"]*)"/', $card, $m) ? $m[1] : []), 'promotion card on Dhiran');

        $this->app->instance('request', \Illuminate\Http\Request::create(self::HOST . '/dhiran/verify-email'));
        $mail = new EmailOtpMail('123456', 'Pawn Co');
        $this->assertDoesNotMatchRegularExpression('/jewel\s?flows/i', $mail->envelope()->subject . $mail->render(), 'OTP email to a Dhiran user');
    }

    public function test_new_loan_page_has_no_old_loan_entry(): void
    {
        $shop = Shop::create(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390020000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390020000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create(['mobile_number' => '9390020000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'A', 'last_name' => 'B', 'mobile' => '9810020001']));

        $page = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::HOST . '/dhiran/create')->getContent());
        foreach (['brought_forward', 'paper_loan_number', 'principal_owed', 'interest_paid_until', 'unpaid_interest'] as $field) {
            $this->assertStringNotContainsString("name=\"{$field}\"", $page, $field);
        }

        // Even if posted, New Loan makes an ordinary new loan: its own number, the payout in the cash book.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::HOST . '/dhiran', [
            'idempotency_key' => 'nobrand-0001', 'customer_id' => $customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]],
            'brought_forward' => 1, 'paper_loan_number' => 'P-1', 'principal_owed' => 5000, 'interest_paid_until' => today()->toDateString(),
        ]))->assertSessionHasNoErrors();
        $loan = DhiranLoan::withoutGlobalScopes()->where('shop_id', $shop->id)->sole();
        $this->assertFalse((bool) $loan->brought_forward);
        $this->assertNotSame('P-1', $loan->loan_number);
        $this->assertSame(1, DhiranCashEntry::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('type', 'out')->count());
    }
}
