<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Occasions were measured from now() including the time of day: in the evening a birthday four days away showed
 * "3 days", and a birthday today was dropped from the list (negative fractional difference).
 */
class CustomerUpcomingOccasionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_days_until_counts_calendar_days_and_keeps_todays_occasion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 21:08:00'));

        $customer = new Customer();
        $customer->date_of_birth = Carbon::parse('1990-11-06');     // today
        $customer->anniversary_date = Carbon::parse('2015-11-10');  // in 4 days
        $customer->wedding_date = Carbon::parse('2015-11-25');      // in 19 days

        $byType = collect($customer->upcomingOccasions(30))->keyBy('type');

        $this->assertSame(0, $byType['Birthday']['days_until'] ?? null, 'a birthday today must be listed as due today');
        $this->assertSame(4, $byType['Anniversary']['days_until']);
        $this->assertSame(19, $byType['Wedding Anniversary']['days_until']);
        $this->assertCount(2, $customer->upcomingOccasions(7) + [], 'today and in-4-days fall inside a 7-day window');
    }
}
