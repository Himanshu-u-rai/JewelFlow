<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_last_super_admin() RETURNS trigger AS $$
DECLARE
    active_super_admin_count bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.role = 'super_admin' AND OLD.is_active = true THEN
            SELECT COUNT(*) INTO active_super_admin_count
            FROM platform_admins
            WHERE role = 'super_admin'
              AND is_active = true
              AND id <> OLD.id;

            IF active_super_admin_count < 1 THEN
                RAISE EXCEPTION 'Cannot delete last active super_admin';
            END IF;
        END IF;
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.role = 'super_admin' AND OLD.is_active = true AND (NEW.role <> 'super_admin' OR NEW.is_active = false) THEN
            SELECT COUNT(*) INTO active_super_admin_count
            FROM platform_admins
            WHERE role = 'super_admin'
              AND is_active = true
              AND id <> OLD.id;

            IF active_super_admin_count < 1 THEN
                RAISE EXCEPTION 'Cannot remove or suspend last active super_admin';
            END IF;
        END IF;
        RETURN NEW;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS protect_last_super_admin_trigger ON platform_admins');
        DB::statement('CREATE TRIGGER protect_last_super_admin_trigger BEFORE UPDATE OR DELETE ON platform_admins FOR EACH ROW EXECUTE FUNCTION protect_last_super_admin()');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS protect_last_super_admin_trigger ON platform_admins');
        DB::statement('DROP FUNCTION IF EXISTS protect_last_super_admin()');
    }
};
