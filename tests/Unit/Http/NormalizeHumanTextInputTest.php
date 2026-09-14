<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\NormalizeHumanTextInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The middleware tidies human-typed labels on the way in. Its job is to clean up
 * sloppy entry, NOT to overrule the operator.
 *
 * The defect this pins: `normalizeTitleText()` applied `MB_CASE_TITLE`
 * unconditionally, and that function lowercases every character it does not
 * capitalize. A name the operator capitalized on purpose came back damaged --
 * "JewelFlows" -> "Jewelflows", "RK Jewellers" -> "Rk Jewellers", "TBZ" -> "Tbz"
 * -- and the original spelling is not recoverable from the stored value.
 *
 * It reached far past shop names: the rule fires on `name`, every `*_name` key,
 * and anything containing "address", so customer names, item names, payment
 * method names and export preset names were all affected by the same line.
 *
 * PHPUnit's TestCase, not Laravel's -- the middleware touches nothing but the
 * request, and the answer must not be able to vary by environment or database.
 */
class NormalizeHumanTextInputTest extends TestCase
{
    /**
     * The regression itself. Every one of these came back mangled before the fix.
     *
     * @return array<string, array{string}>
     */
    public static function deliberatelyCapitalizedNames(): array
    {
        return [
            'internal caps'           => ['JewelFlows'],
            'leading acronym'         => ['RK Jewellers'],
            'acronym with suffix'     => ['TBZ - The Original'],
            'lowercase particle'      => ['M/s ABC Jewellers'],
            'all-caps brand'          => ['GRT Jewellers'],
            'apostrophe'              => ["D'Souza Jewels"],
            'lowercase first letter'  => ['iGold Retail'],
            'camel case word'         => ['Shree MahaLaxmi Jewellers'],
            'acronym alone'           => ['ABC'],
            'acronym mid-string'      => ['Monthly CA Export'],
        ];
    }

    #[DataProvider('deliberatelyCapitalizedNames')]
    public function test_capitalization_the_operator_chose_is_preserved(string $name): void
    {
        $request = $this->pass(['name' => $name]);

        $this->assertSame($name, $request->input('name'),
            "'{$name}' was rewritten; the operator's own spelling of their name was lost");
    }

    /**
     * The other half of the bargain. An all-lowercase entry carries no case
     * decision to preserve, so tidying it is a pure improvement and the helpful
     * behaviour the middleware exists for is kept.
     */
    public function test_an_entry_with_no_capitalization_is_still_tidied(): void
    {
        $request = $this->pass(['name' => 'abc jewellers', 'city' => 'mumbai']);

        $this->assertSame('Abc Jewellers', $request->input('name'));
        $this->assertSame('Mumbai', $request->input('city'));
    }

    /** Whitespace tidying is harmless and applies whichever branch is taken. */
    public function test_whitespace_is_collapsed_and_trimmed_either_way(): void
    {
        $request = $this->pass([
            'name'       => '  RK   Jewellers  ',
            'first_name' => '  abc   jewellers  ',
            'last_name'  => '   ',
        ]);

        $this->assertSame('RK Jewellers', $request->input('name'));
        $this->assertSame('Abc Jewellers', $request->input('first_name'));
        $this->assertSame('', $request->input('last_name'));
    }

    /**
     * The rule is key-driven, so the fix has to hold on every key that reaches
     * title mode -- not just the `name` the defect was reported against.
     */
    public function test_the_fix_holds_on_every_key_that_reaches_title_mode(): void
    {
        $keys = ['name', 'first_name', 'last_name', 'owner_first_name', 'contact_person',
            'display_name', 'category', 'stone_type', 'source_name', 'city', 'state',
            'address', 'address_line1', 'customer_name', 'vendor_name'];

        $request = $this->pass(array_fill_keys($keys, 'RK Jewellers'));

        foreach ($keys as $key) {
            $this->assertSame('RK Jewellers', $request->input($key), "{$key} was still mangled");
        }
    }

    /**
     * Sentence mode only ever capitalizes a line's first letter; it never
     * lowercases, so it had no defect and must not have acquired one.
     */
    public function test_sentence_mode_is_unchanged(): void
    {
        $request = $this->pass(['notes' => "first line\nsecond LINE here"]);

        $this->assertSame("First line\nSecond LINE here", $request->input('notes'));
    }

    /** Identifier and sensitive keys stay out of scope entirely. */
    public function test_excluded_keys_are_untouched(): void
    {
        $untouched = [
            'email'      => 'Foo@Bar.COM',
            'slug'       => 'abc-jewellers',
            'gst_number' => '27aabcu9603r1zm',
            'huid'       => 'ab12cd',
            'password'   => 'correct horse battery',
        ];

        $request = $this->pass($untouched);

        foreach ($untouched as $key => $value) {
            $this->assertSame($value, $request->input($key), "{$key} should never be normalized");
        }
    }

    /** Reads are none of its business. */
    public function test_a_get_request_is_not_rewritten(): void
    {
        $request = Request::create('/', 'GET', ['name' => 'abc jewellers']);

        (new NormalizeHumanTextInput())->handle($request, fn () => new \Symfony\Component\HttpFoundation\Response());

        $this->assertSame('abc jewellers', $request->input('name'));
    }

    /** @param array<string, mixed> $input */
    private function pass(array $input): Request
    {
        $request = Request::create('/', 'POST', $input);

        (new NormalizeHumanTextInput())->handle($request, fn () => new \Symfony\Component\HttpFoundation\Response());

        return $request;
    }
}
