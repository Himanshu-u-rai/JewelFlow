<?php

// Synthetic browser fixtures only. Every operation is guarded before writes.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    TestDatabaseGuard::enforce($app);
    $app['config']->set('cache.default', 'array');
});
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::enforce($app);
config(['hashing.bcrypt.rounds' => 4, 'mail.default' => 'array']);
if (($argv[1] ?? '') === 'seed') {
    $fixture = new class
    {
        use CreatesTestTenant;

        public function tenant(string $realm, string $email): array
        {
            [$user, $shop] = $this->createRetailerTenant();
            $shop->forceFill(['name' => 'Browser '.$realm.' '.$shop->id])->save();
            if ($realm === 'dhiran') {
                DB::table('shop_editions')->where('shop_id', $shop->id)->update(['edition' => 'dhiran']);
            }
            $user->forceFill(['realm' => $realm, 'email' => $email, 'password' => Hash::make('Browser-test-42!')])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
            $customer = $this->createCustomer($shop->id, ['first_name' => 'Private '.$realm]);

            return ['id' => $user->id, 'shop' => $shop->id, 'name' => $shop->name,
                'mobile' => $user->mobile_number, 'email' => $email, 'customer' => $customer->id];
        }
    };
    $email = 'promotion-browser-'.bin2hex(random_bytes(6)).'@example.test';
    $retail = $fixture->tenant('erp', $email);
    $dhiran = $fixture->tenant('dhiran', $email);
    User::findOrFail($dhiran['id'])->forceFill(['mobile_number' => $retail['mobile']])->save();
    $dhiran['mobile'] = $retail['mobile'];
    echo json_encode(['retail' => $retail, 'dhiran' => $dhiran]);
} else {
    $user = User::findOrFail((int) ($argv[2] ?? 0));
    if (! str_starts_with($user->email, 'promotion-browser-') || ! str_ends_with($user->email, '@example.test')) {
        throw new RuntimeException('Not a browser fixture');
    }
    if ($argv[1] === 'reset-token') {
        echo json_encode(['token' => Password::createToken($user)]);
    } elseif ($argv[1] === 'report') {
        echo json_encode(['preferences' => DB::table('product_promotion_preferences')->where('user_id', $user->id)->get(),
            'source_pending_requests' => DB::table('product_recognition_requests')->where('source_user_id', $user->id)->whereNull('consumed_at')->count(),
            'exposures' => DB::table('product_promotion_exposures as e')->join('product_promotion_preferences as p', 'p.id', '=', 'e.preference_id')->where('p.user_id', $user->id)->count(),
            'active_links' => DB::table('product_recognitions')->where($user->realm.'_user_id', $user->id)->whereNull('revoked_at')->count()]);
    } else {
        throw new RuntimeException('Unknown fixture operation');
    }
}
