<?php

namespace Tests\Feature\Tables;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAnalyticsUiTest extends TestCase
{
    public static function ageDistributions(): array
    {
        return [
            'empty' => [[0, 0, 0, 0, 0], 0, [0, 0, 0, 0, 0]],
            'all fresh stock' => [[4, 0, 0, 0, 0], 4, [100, 0, 0, 0, 0]],
            'mixed' => [[3, 0, 1, 1, 0], 5, [60, 0, 20, 20, 0]],
            'all older stock' => [[0, 0, 0, 0, 4], 4, [0, 0, 0, 0, 100]],
        ];
    }

    #[DataProvider('ageDistributions')]
    public function test_age_distribution_charts_actual_bucket_shares_with_readable_values_and_empty_state(array $counts, int $total, array $shares): void
    {
        $this->assertTrue(view()->exists('inventory.items.partials.aging-overview'));
        $labels = ['0-30 days', '31-60 days', '61-90 days', '91-180 days', '180+ days'];
        $buckets = [];
        foreach ($labels as $i => $label) {
            $buckets[$label] = ['count' => $counts[$i], 'value' => $counts[$i] * 1000, 'items' => []];
        }
        $html = view('inventory.items.partials.aging-overview', [
            'buckets' => $buckets, 'totalItems' => $total, 'totalValue' => $total * 1000,
            'agingSummary' => ['avg_days' => 42, 'aged_count' => $counts[3] + $counts[4]],
            'riskPct' => $shares[3] + $shares[4], 'agedValue' => ($counts[3] + $counts[4]) * 1000,
            'agedValueShare' => $shares[3] + $shares[4], 'topSlowCategories' => collect(),
        ])->render();
        $xp = $this->xpath($html);
        $this->assertSame((string) $total, trim($xp->evaluate('string(//*[@data-aging-total])')));
        $offset = 0;
        foreach ($labels as $i => $label) {
            $row = $xp->query('//*[@data-aging-bucket="'.$label.'"]')->item(0);
            $this->assertNotNull($row);
            $this->assertStringContainsString((string) $counts[$i], $row->textContent);
            $arc = $xp->query('//*[local-name()="circle" and @data-aging-segment="'.$label.'"]')->item(0);
            if ($shares[$i] === 0) {
                $this->assertNull($arc);
            } else {
                $this->assertNotNull($arc);
                $this->assertSame('100', $arc->getAttribute('pathlength'));
                $this->assertEquals($shares[$i], (float) explode(' ', $arc->getAttribute('stroke-dasharray'))[0]);
                $this->assertEquals(-$offset, (float) $arc->getAttribute('stroke-dashoffset'));
            }
            $offset += $shares[$i];
        }
        $this->assertSame($total === 0 ? 1 : 0, $xp->query('//*[@data-aging-empty]')->length);
        $this->assertSame($total > 0 && $counts[3] + $counts[4] === 0 ? 1 : 0, $xp->query('//*[@data-aging-clear]')->length);
        $this->assertSame($counts[3] + $counts[4] > 0 ? 1 : 0, $xp->query('//*[@data-aging-review]')->length);
        $this->assertStringContainsString('42', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
    }

    public static function salesComparisons(): array
    {
        return [
            'no sales' => [[], []],
            'equal values remain separate' => [[['category' => 'Rings', 'sold_count' => 2, 'total_revenue' => 2000], ['category' => 'Chains', 'sold_count' => 2, 'total_revenue' => 2000]], ['50.0%', '50.0%']],
            'zero value sales' => [[['category' => 'Rings', 'sold_count' => 2, 'total_revenue' => 0]], ['100.0%']],
            'different units and revenue' => [[['category' => 'Rings', 'sold_count' => 4, 'total_revenue' => 1000], ['category' => 'Chains', 'sold_count' => 2, 'total_revenue' => 2000]], ['66.7%', '33.3%']],
            'screenshot equal units close revenues' => [[['category' => 'Ring', 'sold_count' => 8, 'total_revenue' => 240760], ['category' => 'Bangle', 'sold_count' => 8, 'total_revenue' => 346120], ['category' => 'Earring', 'sold_count' => 8, 'total_revenue' => 347320], ['category' => 'Necklace', 'sold_count' => 8, 'total_revenue' => 344920]], ['25.0%', '25.0%', '25.0%', '25.0%']],
        ];
    }

    #[DataProvider('salesComparisons')]
    public function test_sell_comparison_keeps_equal_categories_in_separate_named_rows_with_exact_values_and_period_controls(array $rows, array $shares): void
    {
        $this->assertTrue(view()->exists('inventory.items.partials.sell-trend-overview'));
        $categories = collect($rows);
        $this->app['request']->query->set('seller_period', '90');
        $html = view('inventory.items.partials.sell-trend-overview', [
            'sellerByCategory' => $categories, 'sellerTotalSold' => $categories->sum('sold_count'),
            'sellerTotalRevenue' => $categories->sum('total_revenue'), 'sellerTopCategory' => $categories->first(),
            'sellerWeakCategory' => $categories->last(), 'sellerPeriodLabel' => 'Last 90 days', 'sellerPeriod' => '90',
        ])->render();
        $xp = $this->xpath($html);
        $rendered = $xp->query('//tr[@data-sales-category]');
        $this->assertSame(count($rows), $rendered->length);
        foreach ($rows as $i => $row) {
            $element = $rendered->item($i);
            $this->assertSame($row['category'], $element->getAttribute('data-sales-category'));
            $this->assertStringContainsString('₹'.number_format($row['total_revenue'], 0), $element->textContent);
            $this->assertSame((string) $row['sold_count'], trim($xp->evaluate('string(.//*[@data-sales-count])', $element)));
            $this->assertSame($shares[$i], trim($xp->evaluate('string(.//*[@data-sales-share])', $element)));
        }
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
        $this->assertStringContainsString('shown categories', $html);
        $this->assertSame('sellers', $xp->evaluate('string(//form/input[@name="view"]/@value)'));
        $this->assertSame('90', $xp->evaluate('string(//select[@name="seller_period"]/option[@selected]/@value)'));
        $this->assertSame(4, $xp->query('//select[@name="seller_period"]/option')->length);
        $this->assertSame(1, $xp->query('//form/button[@type="submit"]')->length);
        $this->assertSame($rows === [] ? 1 : 0, $xp->query('//*[@data-sales-empty]')->length);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }
}
