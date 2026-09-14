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
 * The defect this pins: the name path applied `MB_CASE_TITLE`, and that function
 * lowercases every character it does not capitalize. A name the operator
 * capitalized on purpose came back damaged -- "JewelFlows" -> "Jewelflows",
 * "RK Jewellers" -> "Rk Jewellers", "TBZ" -> "Tbz" -- and the original spelling
 * is not recoverable from the stored value.
 *
 * It reached far past shop names: the rule fires on `name`, every `*_name` key,
 * and anything containing "address", so customer names, item names, payment
 * method names and export preset names were all affected by the same line.
 *
 * THE SECOND CORRECTION. The first fix kept Title Case for input that contained
 * no uppercase letter, reading "abc jewellers" as an operator who had expressed
 * no preference. That was still the middleware deciding it knew better: typing
 * lowercase is a spelling choice like any other, and the absence of a capital is
 * not consent to add one. The name path now changes no letter's case at all. How
 * a name is DISPLAYED is the view layer's business, where it is reversible.
 *
 * PHPUnit's TestCase, not Laravel's -- the middleware touches nothing but the
 * request, and the answer must not be able to vary by environment or database.
 */
class NormalizeHumanTextInputTest extends TestCase
{
    /**
     * Every spelling an operator might legitimately type. None may come back
     * altered -- the mixed-case ones were mangled by the original defect, the
     * all-lowercase ones by the first attempt at fixing it.
     *
     * @return array<string, array{string}>
     */
    public static function operatorSpellings(): array
    {
        return [
            // Mangled by the original MB_CASE_TITLE.
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

            // Mangled by the "no uppercase letter means no decision" theory.
            'all lowercase'           => ['abc jewellers'],
            'lowercase single word'   => ['mumbai'],
            'lowercase brand'         => ['iphone'],
            'lowercase with initials' => ['r k jewellers'],
        ];
    }

    #[DataProvider('operatorSpellings')]
    public function test_the_spelling_the_operator_typed_is_preserved(string $name): void
    {
        $request = $this->pass(['name' => $name]);

        $this->assertSame($name, $request->input('name'),
            "'{$name}' was rewritten; the operator's own spelling of their name was lost");
    }

    /**
     * The half that remains. Whitespace is a typo, not a decision -- and
     * `normalized_name` collapses it in the index regardless, so tidying here is
     * what keeps validation agreeing with the database.
     */
    public function test_whitespace_is_still_collapsed_and_trimmed(): void
    {
        $request = $this->pass([
            'name'       => '  RK   Jewellers  ',
            'first_name' => "  abc \t  jewellers  ",
            'last_name'  => '   ',
        ]);

        $this->assertSame('RK Jewellers', $request->input('name'));
        $this->assertSame('abc jewellers', $request->input('first_name'),
            'whitespace collapsed, case untouched');
        $this->assertSame('', $request->input('last_name'));
    }

    /**
     * The rule is key-driven, so the fix has to hold on every key that reaches
     * name mode -- not just the `name` the defect was reported against. Both
     * spellings, because both were damaged at some point.
     */
    public function test_the_fix_holds_on_every_key_that_reaches_name_mode(): void
    {
        $keys = ['name', 'first_name', 'last_name', 'owner_first_name', 'contact_person',
            'display_name', 'category', 'stone_type', 'source_name', 'city', 'state',
            'address', 'address_line1', 'customer_name', 'vendor_name'];

        foreach (['RK Jewellers', 'abc jewellers'] as $spelling) {
            $request = $this->pass(array_fill_keys($keys, $spelling));

            foreach ($keys as $key) {
                $this->assertSame($spelling, $request->input($key), "{$key} was still rewritten");
            }
        }
    }

    /**
     * Sentence mode only ever capitalizes a line's FIRST letter and concatenates
     * the rest untouched, so it cannot destroy information the way MB_CASE_TITLE
     * did. It had no defect and is deliberately left alone.
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
