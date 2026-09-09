<?php

namespace Tests\Unit\Historical;

use App\Services\Historical\HistoricalCalculationStateService;
use Tests\TestCase;

/**
 * Auto/manual field state machine, HISTORICAL-BATCH-3-UX-CONTRACT-V2 §9/§10.
 */
class HistoricalCalculationStateServiceTest extends TestCase
{
    private HistoricalCalculationStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HistoricalCalculationStateService();
    }

    public function test_a_fresh_suggestion_starts_in_auto_state(): void
    {
        $state = $this->service->autoState(1000.0, ['rate' => 6000]);

        $this->assertSame(HistoricalCalculationStateService::AUTO, $state['mode']);
        $this->assertSame(1000.0, $state['value']);
        $this->assertFalse($this->service->isManual($state));
    }

    public function test_editing_a_value_flips_it_to_manual(): void
    {
        $auto   = $this->service->autoState(1000.0, ['rate' => 6000]);
        $edited = $this->service->markManual($auto, 1250.0);

        $this->assertTrue($this->service->isManual($edited));
        $this->assertSame(1250.0, $edited['value']);
        // The prior suggestion/inputs are preserved for "Use automatic value".
        $this->assertSame(1000.0, $edited['suggestion']);
        $this->assertSame(['rate' => 6000], $edited['inputs']);
    }

    public function test_source_change_does_not_overwrite_a_manual_value(): void
    {
        $auto   = $this->service->autoState(1000.0, ['rate' => 6000]);
        $manual = $this->service->markManual($auto, 1250.0);

        // Rate moved from 6000 to 6500 — the suggestion would now be different.
        $afterSourceChange = $this->service->reactToSourceChange($manual, 1083.33, ['rate' => 6500]);

        $this->assertTrue($this->service->isManual($afterSourceChange));
        $this->assertSame(1250.0, $afterSourceChange['value'], 'manual value must survive a source change');
        // The fresh suggestion is still recorded so "Use automatic value" has
        // something current to offer, even though `value` didn't move.
        $this->assertSame(1083.33, $afterSourceChange['suggestion']);
    }

    public function test_source_change_recomputes_an_auto_value(): void
    {
        $auto = $this->service->autoState(1000.0, ['rate' => 6000]);

        $afterSourceChange = $this->service->reactToSourceChange($auto, 1083.33, ['rate' => 6500]);

        $this->assertFalse($this->service->isManual($afterSourceChange));
        $this->assertSame(1083.33, $afterSourceChange['value']);
    }

    public function test_use_automatic_value_restores_auto_regardless_of_prior_mode(): void
    {
        $auto   = $this->service->autoState(1000.0, ['rate' => 6000]);
        $manual = $this->service->markManual($auto, 1250.0);

        $restored = $this->service->recalculate(1083.33, ['rate' => 6500]);

        $this->assertFalse($this->service->isManual($restored));
        $this->assertSame(1083.33, $restored['value']);
    }

    public function test_client_submitted_auto_mode_is_honoured_only_when_it_matches_the_server_suggestion(): void
    {
        $state = $this->service->applyClientSubmission(
            claimedMode: HistoricalCalculationStateService::AUTO,
            submittedValue: 1000.0,
            freshSuggestion: 1000.0,
        );

        $this->assertFalse($this->service->isManual($state));
    }

    public function test_a_forged_auto_claim_that_disagrees_with_the_server_suggestion_is_corrected_to_manual(): void
    {
        // Client claims "auto" but submits a value the server's own formula
        // would never produce — never trust the client-submitted mode.
        $state = $this->service->applyClientSubmission(
            claimedMode: HistoricalCalculationStateService::AUTO,
            submittedValue: 99999.0,
            freshSuggestion: 1000.0,
        );

        $this->assertTrue($this->service->isManual($state));
        $this->assertSame(99999.0, $state['value']);
    }

    public function test_client_submitted_manual_mode_is_always_honoured(): void
    {
        $state = $this->service->applyClientSubmission(
            claimedMode: HistoricalCalculationStateService::MANUAL,
            submittedValue: 1250.0,
            freshSuggestion: 1000.0,
        );

        $this->assertTrue($this->service->isManual($state));
        $this->assertSame(1250.0, $state['value']);
    }
}
