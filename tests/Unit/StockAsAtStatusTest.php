<?php

namespace Tests\Unit;

use App\Services\StockAsAt;
use PHPUnit\Framework\TestCase;

class StockAsAtStatusTest extends TestCase
{
    public function test_owned_statuses_count_as_stock(): void
    {
        foreach (['in_stock', 'pending_restock', 'pending_listing', 'with_karigar'] as $s) {
            $this->assertTrue(StockAsAt::isStockStatus($s), $s);
        }
    }

    public function test_gone_statuses_do_not_count_as_stock(): void
    {
        foreach (['sold', 'returned', 'melted', 'transferred', 'reversed', 'deleted', '', null] as $s) {
            $this->assertFalse(StockAsAt::isStockStatus($s), (string) $s);
        }
    }
}
