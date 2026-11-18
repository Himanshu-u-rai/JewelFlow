<?php

namespace Tests\Unit;

use App\Support\StockValuation;
use PHPUnit\Framework\TestCase;

/** Indian AS-2 / ICDS-IV: closing stock is valued at cost or net realisable value, whichever is lower. */
class StockValuationTest extends TestCase
{
    public function test_lower_of_cost_and_market(): void
    {
        $this->assertSame(40000.0, StockValuation::lowerOfCostAndMarket(40000, 62700));
        $this->assertSame(62700.0, StockValuation::lowerOfCostAndMarket(70000, 62700));
        $this->assertSame(500.0, StockValuation::lowerOfCostAndMarket(500, 500));
    }

    public function test_unknown_cost_or_unknown_market_gives_no_valuation(): void
    {
        $this->assertNull(StockValuation::lowerOfCostAndMarket(null, 62700));
        $this->assertNull(StockValuation::lowerOfCostAndMarket(40000, null));
        $this->assertNull(StockValuation::lowerOfCostAndMarket(null, null));
    }

    public function test_a_zero_cost_is_a_known_cost(): void
    {
        $this->assertSame(0.0, StockValuation::lowerOfCostAndMarket(0, 62700));
    }

    public function test_market_value_is_net_metal_weight_times_rate_rounded_to_paise(): void
    {
        $this->assertSame(62700.0, StockValuation::marketValue('9.500000', '6600.0000'));
        $this->assertSame(12.35, StockValuation::marketValue('0.333333', '37.0500'));
        $this->assertNull(StockValuation::marketValue('9.5', null));
        $this->assertNull(StockValuation::marketValue(null, '6600'));
    }
}
