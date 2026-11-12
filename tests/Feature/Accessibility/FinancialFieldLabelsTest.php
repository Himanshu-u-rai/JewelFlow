<?php

namespace Tests\Feature\Accessibility;

use Tests\TestCase;

/**
 * F02: every visible form control in the financial/stock views must have an
 * accessible name (aria-label, aria-labelledby, <label for>, or wrapping label).
 * Source-level check; no database or rendering needed.
 */
class FinancialFieldLabelsTest extends TestCase
{
    private const VIEWS = [
        'schemes/enrollment-show',
        'vault/show-lot',
        'store-credit/adjust',
        'profile/mobile-change',
        'karigar-invoices/show',
        'inventory/items/create-retailer',
        'inventory/items/edit-retailer',
    ];

    public function test_controls_have_accessible_names(): void
    {
        $failures = [];

        foreach (self::VIEWS as $view) {
            $path = resource_path("views/{$view}.blade.php");
            $this->assertFileExists($path);
            $src = file_get_contents($path);

            // ids referenced by <label for="...">
            preg_match_all('/<label\b[^>]*\bfor\s*=\s*(["\'])(.*?)\1/s', $src, $m);
            $forIds = array_map('trim', $m[2]);

            // spans of <label> ... </label> for wrapping checks
            $labelSpans = [];
            if (preg_match_all('/<label\b.*?<\/label>/s', $src, $lm, PREG_OFFSET_CAPTURE)) {
                foreach ($lm[0] as [$text, $off]) {
                    $labelSpans[] = [$off, $off + strlen($text)];
                }
            }

            preg_match_all('/<(input|select|textarea)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $src, $cm, PREG_OFFSET_CAPTURE);
            foreach ($cm[0] as $i => [$tag, $off]) {
                $attrs = $cm[2][$i][0];
                if ($cm[1][$i][0] === 'input'
                    && preg_match('/\btype\s*=\s*["\']?(hidden|submit|button|reset|image)\b/i', $attrs)) {
                    continue;
                }
                if (preg_match('/(^|\s|:)(aria-label|aria-labelledby)\s*=/', $attrs)) {
                    continue;
                }
                if (preg_match('/(^|\s)(?::)?id\s*=\s*(["\'])(.*?)\2/s', $attrs, $idm)
                    && in_array(trim($idm[3]), $forIds, true)) {
                    continue;
                }
                $wrapped = false;
                foreach ($labelSpans as [$s, $e]) {
                    if ($off > $s && $off < $e) {
                        $wrapped = true;
                        break;
                    }
                }
                if ($wrapped) {
                    continue;
                }
                $line = substr_count(substr($src, 0, $off), "\n") + 1;
                $failures[] = "resources/views/{$view}.blade.php:{$line}  ".preg_replace('/\s+/', ' ', substr($tag, 0, 110));
            }
        }

        $this->assertSame([], $failures, "Unlabeled controls:\n".implode("\n", $failures));
    }
}
