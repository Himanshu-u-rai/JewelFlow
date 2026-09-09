<?php

namespace Tests\Unit\Support;

use App\Rules\IndianMobileRule;
use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a mobile number, in one place.
 *
 * Note this extends PHPUnit's TestCase, not Laravel's: App\Support\Mobile has
 * no framework dependency and the whole point of it is that the answer cannot
 * differ by environment. IndianMobileRule is exercised by calling validate()
 * directly for the same reason — no container, no config, no way for the answer
 * to depend on anything but the input.
 */
class MobileNumberTest extends TestCase
{
    private const CANONICAL = '9812300099';

    /** @return array<string, array{0: string}> */
    public static function acceptedSpellings(): array
    {
        return [
            'bare canonical'          => ['9812300099'],
            'E.123 spacing'           => ['98123 00099'],
            'plus country code'       => ['+91 98123 00099'],
            'hyphenated with cc'      => ['+91-98123-00099'],
            'trunk zero'              => ['098123-00099'],
            'cc without plus'         => ['919812300099'],
            'idd prefix'              => ['00919812300099'],
            'parenthesised'           => ['(98123) 00099'],
            'surrounding whitespace'  => ['  9812300099  '],
            'dotted'                  => ['98123.00099'],
        ];
    }

    #[DataProvider('acceptedSpellings')]
    public function test_every_spelling_of_one_number_normalises_to_the_same_string(string $input): void
    {
        $this->assertSame(self::CANONICAL, Mobile::normalize($input),
            "'{$input}' did not reduce to the canonical form");
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function rejectedValues(): array
    {
        return [
            // The reason substr(-10) had to go: this is a typo, not a country
            // code, and trimming it yields 8123000999 — a real, valid, DIFFERENT
            // person's number. A prefix is only stripped when the remainder is
            // exactly ten digits, so an eleventh digit is rejected instead.
            'eleven digits, no prefix'  => ['98123000999', 'a fat-fingered extra digit was silently trimmed into another valid number'],
            'nine digits'               => ['981230009',   'too short to be a mobile'],
            'landline level 2'          => ['2212345678',  'landline area codes are not mobiles (TRAI levels 2-5)'],
            'service level 1'           => ['1234567890',  'level 1 is service numbers, not mobiles'],
            'all zeroes'                => ['0000000000',  'digits:10 used to accept this'],
            'starts with 5'             => ['5812300099',  'a mobile begins 6, 7, 8 or 9'],
            'foreign number'            => ['+1 415 555 0132', 'India-only: a US number is not storable here'],
            'far too short'             => ['98123',       'a scrap is not a number'],
            'letters in front'          => ['ABC9812300099', 'letters must not be stripped away into a pass'],
            'letters inside'            => ['98123abc00099', 'letters must not be stripped away into a pass'],
            'empty'                     => ['',            'nothing is not a number'],
            'whitespace only'           => ['   ',         'nothing is not a number'],
            'null'                      => [null,          'null is not a number'],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_a_value_that_is_not_an_indian_mobile_normalises_to_null(?string $input, string $why): void
    {
        $this->assertNull(Mobile::normalize($input), $why);
        $this->assertFalse(Mobile::isValid($input), $why);
    }

    /**
     * Normalising is idempotent, which is what lets the mutator run on every
     * write without caring whether the value has been through it before.
     */
    public function test_normalising_a_canonical_number_leaves_it_alone(): void
    {
        $this->assertSame(self::CANONICAL, Mobile::normalize(Mobile::normalize(self::CANONICAL)));
    }

    /** E.123: store bare, group for humans. Display must never change identity. */
    public function test_display_grouping_is_presentation_only(): void
    {
        $this->assertSame('98123 00099', Mobile::forDisplay('+91-98123-00099'));
        $this->assertSame(self::CANONICAL, Mobile::normalize(Mobile::forDisplay(self::CANONICAL)));
    }

    /** A legacy row must still render as whatever the shop typed, not vanish. */
    public function test_display_falls_back_to_the_stored_string(): void
    {
        $this->assertSame('98123', Mobile::forDisplay('  98123  '));
        $this->assertSame('', Mobile::forDisplay(null));
    }

    /**
     * wa.me needs the country code back. Storing the bare national number is
     * the right call for a single-country app, but it has exactly one cost and
     * this is it — a link built from the raw column opens a dead chat, silently,
     * on the public catalog where nobody internal would ever notice.
     */
    public function test_whatsapp_links_get_the_country_code_back(): void
    {
        $this->assertSame('919812300099', Mobile::forWhatsApp(self::CANONICAL));
        $this->assertSame('919812300099', Mobile::forWhatsApp('+91 98123 00099'));
    }

    /** Idempotent for the rows shops typed with the country code already in. */
    public function test_a_number_already_carrying_the_country_code_is_not_doubled(): void
    {
        $this->assertSame('919812300099', Mobile::forWhatsApp('919812300099'));
    }

    /** A stored value that is not a mobile keeps whatever link it had before. */
    public function test_whatsapp_falls_back_to_bare_digits(): void
    {
        $this->assertSame('98123', Mobile::forWhatsApp('98123'));
        $this->assertSame('', Mobile::forWhatsApp(null));
    }

    // ------------------------------------------------------------ the rule

    /**
     * The rule and the normaliser must never disagree — a value the form accepts
     * but the column cannot store (or vice versa) is the exact defect this
     * consolidation exists to remove.
     */
    #[DataProvider('acceptedSpellings')]
    public function test_the_rule_accepts_everything_the_normaliser_accepts(string $input): void
    {
        $this->assertTrue($this->passes($input), "the rule rejected '{$input}', which normalises fine");
    }

    #[DataProvider('rejectedValues')]
    public function test_the_rule_rejects_everything_the_normaliser_rejects(?string $input, string $why): void
    {
        $this->assertFalse($this->passes($input), $why);
    }

    /**
     * The operator is holding a number and needs to know what to do with it, so
     * the message has to name the actual problem rather than say "invalid".
     */
    public function test_the_failure_message_names_the_real_problem(): void
    {
        $this->assertStringContainsString('letters', $this->error('98123abc00099'));
        $this->assertStringContainsString('only 9', $this->error('981230009'));
        $this->assertStringContainsString('country code', $this->error('98123000999'));
        $this->assertStringContainsString('Landline', $this->error('2212345678'));
    }

    private function passes(?string $value): bool
    {
        $failed = false;
        (new IndianMobileRule())->validate('mobile', $value, function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }

    private function error(?string $value): string
    {
        $message = '';
        (new IndianMobileRule())->validate('mobile', $value, function (string $m) use (&$message): void {
            $message = $m;
        });

        return $message;
    }
}
