<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\CanonicaliseMobileInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The request-side half of canonicalisation.
 *
 * The model mutator makes the COLUMN consistent; this makes the VALIDATOR see
 * the same string the column will get. Both are needed: Rule::unique runs before
 * the mutator and compares raw input, so without this a duplicate typed with
 * spaces slips past validation and dies on the unique index instead.
 *
 * PHPUnit's TestCase, not Laravel's — the middleware touches nothing but the
 * request, and the answer must not be able to vary by environment.
 */
class CanonicaliseMobileInputTest extends TestCase
{
    public function test_a_mobile_typed_in_any_spelling_reaches_validation_canonical(): void
    {
        $request = $this->pass('POST', ['mobile' => '+91 98123 00099']);

        $this->assertSame('9812300099', $request->input('mobile'));
    }

    /**
     * The negative branch, and the reason this only rewrites valid numbers: if a
     * bad value were mangled on the way in, IndianMobileRule would report on a
     * string the operator never typed. "only 5 digits" is actionable; a message
     * about some rewritten form is not.
     */
    public function test_a_value_that_is_not_a_mobile_is_passed_through_untouched(): void
    {
        foreach (['98123', '2212345678', '98123abc00099', '', '   '] as $bad) {
            $request = $this->pass('POST', ['mobile' => $bad]);

            $this->assertSame($bad, $request->input('mobile'),
                "'{$bad}' was rewritten; the rule can no longer name the real problem");
        }
    }

    /** Every key the rule is applied to, or the ones missed keep the old defect. */
    public function test_all_the_mobile_keys_are_covered(): void
    {
        $keys = ['mobile', 'mobile_number', 'new_mobile', 'new_mobile_number',
            'customer_mobile', 'owner_mobile', 'phone', 'shop_whatsapp', 'social_whatsapp'];

        $request = $this->pass('POST', array_fill_keys($keys, '+91 98123 00099'));

        foreach ($keys as $key) {
            $this->assertSame('9812300099', $request->input($key), "{$key} was not canonicalised");
        }
    }

    /** Other fields are none of its business. */
    public function test_an_unrelated_field_holding_digits_is_left_alone(): void
    {
        $request = $this->pass('POST', ['gst_number' => '9812300099', 'aadhaar' => '+91 98123 00099']);

        $this->assertSame('9812300099', $request->input('gst_number'));
        $this->assertSame('+91 98123 00099', $request->input('aadhaar'));
    }

    /**
     * Reads are left alone. Nothing is written on a GET, and a lookup against an
     * un-backfilled column may legitimately need the string as typed.
     */
    public function test_a_get_request_is_not_rewritten(): void
    {
        $request = $this->pass('GET', ['mobile' => '+91 98123 00099']);

        $this->assertSame('+91 98123 00099', $request->input('mobile'));
    }

    /** The mobile app posts JSON; merge() has to reach that input source too. */
    public function test_a_json_body_is_rewritten(): void
    {
        $request = Request::create('/api/mobile/customers', 'POST',
            content: json_encode(['mobile' => '+91 98123 00099']),
            server: ['CONTENT_TYPE' => 'application/json'],
        );

        (new CanonicaliseMobileInput())->handle($request, fn () => new \Symfony\Component\HttpFoundation\Response());

        $this->assertSame('9812300099', $request->input('mobile'));
    }

    /** @param array<string, mixed> $input */
    private function pass(string $method, array $input): Request
    {
        $request = Request::create('/', $method, $input);

        (new CanonicaliseMobileInput())->handle($request, fn () => new \Symfony\Component\HttpFoundation\Response());

        return $request;
    }
}
