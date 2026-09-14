<?php

namespace Tests\Unit\Platform;

use App\Models\Platform\Plan;
use PHPUnit\Framework\TestCase;

/**
 * A plan sells the cycles it prices, and only those.
 *
 * `plans` has two price columns and no column saying which cycles the plan is
 * sold on — an unpriced cycle IS the way "not sold" is expressed, and the
 * seeded `retailer_monthly`, `manufacturer_monthly` and `dhiran_monthly` all
 * ship with a null `price_yearly`. Every caller therefore had to repeat the
 * same two-part test against the raw columns, and the two that forgot are how
 * "yearly" became selectable on a plan that has no yearly price.
 *
 * These assertions are the contract the callers now lean on. No database: a
 * Plan is asked about its own columns.
 */
class PlanCycleSupportTest extends TestCase
{
    private function plan(array $attributes): Plan
    {
        // forceFill, not the constructor: mass assignment consults the table
        // schema to decide what is guardable, and these cases must not need a
        // database to answer a question about two columns already in hand.
        return (new Plan)->forceFill($attributes);
    }

    public function test_a_priced_cycle_is_sold_and_reports_its_price(): void
    {
        $plan = $this->plan(['price_monthly' => 4630.00, 'price_yearly' => 50000.00]);

        $this->assertTrue($plan->supportsCycle('monthly'));
        $this->assertTrue($plan->supportsCycle('yearly'));
        $this->assertSame(4630.00, $plan->priceFor('monthly'));
        $this->assertSame(50000.00, $plan->priceFor('yearly'));
    }

    /** The seeded shape of every `*_monthly` plan. */
    public function test_a_null_price_means_the_cycle_is_not_sold(): void
    {
        $plan = $this->plan(['price_monthly' => 4630.00, 'price_yearly' => null]);

        $this->assertTrue($plan->supportsCycle('monthly'));
        $this->assertFalse($plan->supportsCycle('yearly'), 'A plan with no yearly price is not sold yearly.');
        $this->assertNull($plan->priceFor('yearly'));
    }

    /**
     * Zero is not a free plan, it is an unfinished one — nothing in this system
     * charges ₹0, and the pre-existing guards at the payment screens already
     * treated `<= 0` as unsellable. Same answer here, in one place.
     */
    public function test_a_zero_or_negative_price_is_not_sold(): void
    {
        $this->assertFalse($this->plan(['price_yearly' => 0])->supportsCycle('yearly'));
        $this->assertFalse($this->plan(['price_yearly' => '0.00'])->supportsCycle('yearly'));
        $this->assertFalse($this->plan(['price_monthly' => -1])->supportsCycle('monthly'));
    }

    /**
     * Mirrors SubscriptionTerm: 'yearly' is the only yearly spelling, and
     * anything else is treated as the monthly term rather than silently
     * answering about a cycle nobody asked for.
     */
    public function test_anything_that_is_not_yearly_is_asked_of_the_monthly_price(): void
    {
        $plan = $this->plan(['price_monthly' => 1852.00, 'price_yearly' => null]);

        $this->assertSame(1852.00, $plan->priceFor('monthly'));
        $this->assertSame(1852.00, $plan->priceFor('quarterly'));
        $this->assertSame(1852.00, $plan->priceFor(''));
    }
}
