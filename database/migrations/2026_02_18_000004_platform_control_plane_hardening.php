<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $trueExpr = $isPgsql ? 'TRUE' : '1';
        $falseExpr = $isPgsql ? 'FALSE' : '0';

        if (!Schema::hasTable('platform_admins')) {
            Schema::create('platform_admins', function (Blueprint $table) {
                $table->id();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('name', 200);
                $table->string('email')->nullable()->unique();
                $table->string('mobile_number', 20)->unique();
                $table->string('password');
                $table->string('role', 32)->default('super_admin'); // super_admin, platform_operator
                $table->boolean('is_active')->default(true);
                $table->boolean('two_factor_enabled')->default(false);
                $table->text('two_factor_secret')->nullable();
                $table->timestamp('password_changed_at')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_super_admin')) {
            $legacyAdmins = DB::table('users')
                ->whereRaw("is_super_admin = {$trueExpr}")
                ->orderBy('id')
                ->get();
            foreach ($legacyAdmins as $admin) {
                $firstName = (string) (data_get($admin, 'first_name') ?: 'Platform');
                $lastName = (string) (data_get($admin, 'last_name') ?: 'Admin');
                DB::table('platform_admins')->updateOrInsert(
                    ['mobile_number' => (string) $admin->mobile_number],
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'name' => trim($firstName . ' ' . $lastName),
                        'email' => data_get($admin, 'email'),
                        'password' => data_get($admin, 'password'),
                        'role' => 'super_admin',
                        'is_active' => (bool) (data_get($admin, 'is_active') ?? true),
                        'created_at' => data_get($admin, 'created_at') ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'access_mode')) {
                $table->string('access_mode', 20)->default('active')->after('is_active');
                $table->index('access_mode');
            }
            if (!Schema::hasColumn('shops', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('access_mode');
            }
            if (!Schema::hasColumn('shops', 'suspended_by')) {
                $table->foreignId('suspended_by')->nullable()->after('suspended_at')
                    ->constrained('platform_admins')->restrictOnDelete();
            }
            if (!Schema::hasColumn('shops', 'suspension_reason')) {
                $table->text('suspension_reason')->nullable()->after('suspended_by');
            }
            if (!Schema::hasColumn('shops', 'suspended_until')) {
                $table->timestamp('suspended_until')->nullable()->after('suspension_reason');
            }
        });

        $currentTimestampExpr = DB::getDriverName() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';

        if (Schema::hasColumn('shops', 'is_active')) {
            DB::table('shops')
                ->whereRaw("is_active = {$falseExpr}")
                ->update([
                    'access_mode' => 'suspended',
                    'suspended_at' => DB::raw("COALESCE(deactivated_at, {$currentTimestampExpr})"),
                    'suspension_reason' => DB::raw("COALESCE(suspension_reason, 'Legacy deactivation migration')"),
                ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE shops DROP CONSTRAINT IF EXISTS shops_access_mode_check");
            DB::statement("ALTER TABLE shops ADD CONSTRAINT shops_access_mode_check CHECK (access_mode IN ('active', 'read_only', 'suspended'))");
        }

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->nullable();
            $table->integer('grace_days')->default(0);
            $table->boolean('downgrade_to_read_only_on_due')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 24)->default('trial'); // trial, active, grace, read_only, suspended, cancelled, expired
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('updated_by_admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'ends_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE shop_subscriptions DROP CONSTRAINT IF EXISTS shop_subscriptions_status_check");
            DB::statement("ALTER TABLE shop_subscriptions ADD CONSTRAINT shop_subscriptions_status_check CHECK (status IN ('trial', 'active', 'grace', 'read_only', 'suspended', 'cancelled', 'expired'))");
        }

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_subscription_id')->constrained('shop_subscriptions')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->string('event_type', 64); // created, renewed, moved_to_grace, set_read_only, suspended, restored, cancelled
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
        });

        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION platform_audit_logs_append_only_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'platform_audit_logs is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement('DROP TRIGGER IF EXISTS platform_audit_logs_append_only_trigger ON platform_audit_logs');
            DB::statement('CREATE TRIGGER platform_audit_logs_append_only_trigger BEFORE UPDATE OR DELETE ON platform_audit_logs FOR EACH ROW EXECUTE FUNCTION platform_audit_logs_append_only_guard()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS platform_audit_logs_append_only_trigger ON platform_audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS platform_audit_logs_append_only_guard()');
        }

        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('shop_subscriptions');
        Schema::dropIfExists('plans');

        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'suspended_until')) {
                $table->dropColumn('suspended_until');
            }
            if (Schema::hasColumn('shops', 'suspension_reason')) {
                $table->dropColumn('suspension_reason');
            }
            if (Schema::hasColumn('shops', 'suspended_by')) {
                $table->dropConstrainedForeignId('suspended_by');
            }
            if (Schema::hasColumn('shops', 'suspended_at')) {
                $table->dropColumn('suspended_at');
            }
            if (Schema::hasColumn('shops', 'access_mode')) {
                $table->dropIndex(['access_mode']);
                $table->dropColumn('access_mode');
            }
        });

        Schema::dropIfExists('platform_admins');
    }
};
