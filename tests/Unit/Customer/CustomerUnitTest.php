<?php

namespace Tests\Unit\Customer;

use App\Rules\PanFormatRule;
use App\Support\AadhaarMask;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Customer Management — unit level (Module 6). PAN format validation and the
 * Aadhaar masking helper (now used by the ERP customer compliance display).
 */
class CustomerUnitTest extends TestCase
{
    private function panFails(string $pan): bool
    {
        return Validator::make(['pan' => $pan], ['pan' => [new PanFormatRule()]])->fails();
    }

    // ── PAN format ─────────────────────────────────────────────────────────

    public function test_valid_pan_passes(): void
    {
        $this->assertFalse($this->panFails('ABCDE1234F'));
    }

    public function test_invalid_pan_is_rejected(): void
    {
        foreach (['ABCD1234F', 'ABCDE12345', '12345ABCDF', 'ABCDE1234', 'ABCDE-1234F', 'ABCDE1234FG'] as $bad) {
            $this->assertTrue($this->panFails($bad), "{$bad} must be rejected");
        }
    }

    public function test_lowercase_pan_is_normalised_and_accepted(): void
    {
        // The rule strtoupper+trims before matching, so lowercase is valid input.
        $this->assertFalse($this->panFails('abcde1234f'));
        $this->assertFalse($this->panFails('  ABCDE1234F  '));
    }

    // ── Aadhaar masking (privacy) ──────────────────────────────────────────

    public function test_full_aadhaar_is_masked_to_last_four(): void
    {
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('123412341234'));
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('1234 1234 1234'));
        $this->assertSame('XXXX-XXXX-1234', AadhaarMask::mask('1234-1234-1234'));
    }

    public function test_already_masked_value_stays_masked(): void
    {
        $this->assertSame('XXXX-XXXX-5678', AadhaarMask::mask('XXXX-XXXX-5678'));
        $this->assertTrue(AadhaarMask::isMasked('XXXX-XXXX-5678'));
        $this->assertFalse(AadhaarMask::isMasked('123412341234'));
    }

    public function test_mask_never_returns_the_full_number(): void
    {
        $masked = AadhaarMask::mask('999988887777');
        $this->assertNotNull($masked);
        $this->assertStringNotContainsString('99998888', (string) $masked, 'full Aadhaar digits must not survive masking');
        $this->assertStringContainsString('7777', (string) $masked); // last 4 retained
    }

    public function test_blank_or_too_short_returns_null(): void
    {
        $this->assertNull(AadhaarMask::mask(null));
        $this->assertNull(AadhaarMask::mask(''));
        $this->assertNull(AadhaarMask::mask('12'));
    }
}
