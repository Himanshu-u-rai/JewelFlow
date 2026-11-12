<?php

namespace Tests\Feature\Feedback;

use PHPUnit\Framework\TestCase;

/**
 * F07: no native browser alert()/confirm() in these views. Confirmations are a form
 * `onsubmit="return confirm('…')"`, which resources/js/app.js lifts into the themed
 * confirmation dialog; messages go through window.showToast. Source-level, no DB.
 */
class NoNativeDialogsTest extends TestCase
{
    private const VIEWS = [
        'inventory/items/show',
        'inventory/items/create-retailer',
        'inventory/items/edit-retailer',
        'inventory/purchases/show',
        'returns/approve',
        'installments/create',
        'dashboard',
    ];

    private function violations(string $pattern): array
    {
        $found = [];
        foreach (self::VIEWS as $view) {
            $path = dirname(__DIR__, 3).'/resources/views/'.$view.'.blade.php';
            $this->assertFileExists($path);
            foreach (file($path) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $found[] = $view.'.blade.php:'.($i + 1).': '.trim($line);
                }
            }
        }

        return $found;
    }

    public function test_no_native_alert_remains(): void
    {
        $this->assertSame([], $this->violations('/(?<![\w$])alert\s*\(/'), 'Use window.showToast instead of alert().');
    }

    public function test_confirm_is_only_used_as_a_form_onsubmit_the_shared_dialog_lifts(): void
    {
        // Any confirm( call that is not exactly `onsubmit="return confirm('…')`.
        $bad = array_filter(
            $this->violations('/(?<![\w$])confirm\s*\(/'),
            fn ($hit) => ! preg_match('/<form\b[^>]*\sonsubmit="return confirm\(\'/', $hit)
        );

        $this->assertSame([], array_values($bad), 'Put the confirm in the <form onsubmit="return confirm(\'…\')">.');
    }

    public function test_confirm_forms_keep_their_guardrail_messages(): void
    {
        $expect = [
            'inventory/purchases/show' => ['Confirm this purchase?', 'Add items from this purchase to shop inventory?', 'Reverse this purchase back to a draft?'],
            'returns/approve' => ['Approve this return and issue the credit note?', 'Reject this return? This cannot be undone.'],
            'installments/create' => ['Cancel this EMI checkout?'],
        ];
        foreach ($expect as $view => $messages) {
            $source = file_get_contents(dirname(__DIR__, 3).'/resources/views/'.$view.'.blade.php');
            foreach ($messages as $message) {
                $this->assertMatchesRegularExpression(
                    '/<form\b[^>]*\sonsubmit="return confirm\(\''.preg_quote($message, '/').'/',
                    $source,
                    "$view: missing form onsubmit confirm for \"$message\""
                );
            }
        }

        // Shop-open toggle: closing carries the message to the delegated confirm handler.
        $dashboard = file_get_contents(dirname(__DIR__, 3).'/resources/views/dashboard.blade.php');
        $this->assertStringContainsString("dataset.confirmMessage='Close the shop?", $dashboard);
        $this->assertStringNotContainsString('this.form.submit()', $dashboard);
    }
}
