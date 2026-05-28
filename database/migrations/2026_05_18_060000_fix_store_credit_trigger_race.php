<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Replace the balance-guard function with a version that acquires a
        // FOR UPDATE row-level lock on the customer row before summing
        // movements. This serializes concurrent inserts for the same customer,
        // preventing two simultaneous debits from both reading the same
        // pre-debit balance and both passing the non-negative check.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_credit_non_negative_guard() RETURNS trigger AS $$
DECLARE
    running_total numeric;
BEGIN
    -- Acquire an exclusive row-level lock on the customer row so that
    -- concurrent inserts for the same customer are serialized. Without
    -- this lock two concurrent BEFORE INSERT triggers can both read the
    -- same balance and both succeed, producing an overdraft.
    PERFORM id FROM customers WHERE id = NEW.customer_id FOR UPDATE;

    SELECT COALESCE(SUM(amount), 0)
      INTO running_total
      FROM store_credit_movements
     WHERE shop_id = NEW.shop_id
       AND customer_id = NEW.customer_id;

    IF (running_total + NEW.amount) < 0 THEN
        RAISE EXCEPTION
            'Store credit overdraft: customer % balance % cannot absorb movement %',
            NEW.customer_id, running_total, NEW.amount;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        // Restore the original non-locking version.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION store_credit_non_negative_guard() RETURNS trigger AS $$
DECLARE
    running_total numeric;
BEGIN
    SELECT COALESCE(SUM(amount), 0)
      INTO running_total
      FROM store_credit_movements
     WHERE shop_id = NEW.shop_id
       AND customer_id = NEW.customer_id;

    IF (running_total + NEW.amount) < 0 THEN
        RAISE EXCEPTION
            'Store credit overdraft: customer % balance % cannot absorb movement %',
            NEW.customer_id, running_total, NEW.amount;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};
