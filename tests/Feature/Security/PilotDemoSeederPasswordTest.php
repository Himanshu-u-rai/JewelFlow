<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\PilotDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * The demo seeder's built-in password is published with the repository. On
 * 2026-10-01 three accounts on the internet-facing staging site still accepted
 * it. Outside local and testing the seeder must be given a password, or refuse.
 */
class PilotDemoSeederPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function builtInDefault(): string
    {
        return (new ReflectionClassConstant(PilotDemoSeeder::class, 'DEMO_PASSWORD'))->getValue();
    }

    private function demoUsers()
    {
        return User::withoutGlobalScopes()->where('mobile_number', 'like', '90000001%')->get();
    }

    protected function tearDown(): void
    {
        putenv('PILOT_DEMO_PASSWORD');
        parent::tearDown();
    }

    public function test_outside_local_and_testing_it_refuses_to_use_the_published_default(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->seed(PilotDemoSeeder::class);
            $this->fail('The seeder ran on staging without a password.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PILOT_DEMO_PASSWORD', $e->getMessage());
        }

        $this->assertCount(0, $this->demoUsers(), 'No demo account may exist after a refused run.');
    }

    public function test_outside_local_and_testing_the_accounts_get_the_password_it_was_given(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        putenv('PILOT_DEMO_PASSWORD=a-password-chosen-for-this-run');

        $this->seed(PilotDemoSeeder::class);

        $users = $this->demoUsers();
        $this->assertCount(3, $users);
        foreach ($users as $user) {
            $this->assertTrue(Hash::check('a-password-chosen-for-this-run', $user->password));
            $this->assertFalse(Hash::check($this->builtInDefault(), $user->password));
        }
    }

    public function test_being_given_the_published_default_is_the_same_as_being_given_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        putenv('PILOT_DEMO_PASSWORD='.$this->builtInDefault());

        $this->expectException(\RuntimeException::class);
        $this->seed(PilotDemoSeeder::class);
    }

    public function test_in_testing_the_default_still_works_so_local_setup_is_unchanged(): void
    {
        $this->seed(PilotDemoSeeder::class);

        $users = $this->demoUsers();
        $this->assertCount(3, $users);
        $this->assertTrue(Hash::check($this->builtInDefault(), $users->first()->password));
    }
}
