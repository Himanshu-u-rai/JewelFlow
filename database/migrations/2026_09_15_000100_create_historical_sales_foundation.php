<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical Sales — Batch 1: canonical record-only data model.
 *
 * ARCHITECTURE (Option C, owner-locked). Historical sales are documents the shop
 * issued BEFORE JewelFlow. They are a RECORD of the past, not a transaction in
 * the present. They therefore live in their own tables and never enter the live
 * invoice pipeline.
 *
 * This migration deliberately does NOT touch `invoices`, `invoice_items` or
 * `quick_bills`, and adds no `source` / `is_historical` discriminator to them.
 * The isolation is structural: `SELECT ... FROM invoices` physically cannot
 * return a historical row, so every one of the 48 unscoped operational invoice
 * queries stays correct with zero changes and zero developer discipline.
 *
 * ORIGINAL DOCUMENT NUMBER. The client's printed number is statutory evidence
 * and is stored EXACTLY as supplied (leading zeroes, slashes, spaces, case).
 * JewelFlow never generates a number for a historical document and never touches
 * `shop_counters`, `invoice_number_events` or BusinessIdentifierService. The
 * `*_normalized` column exists only for search and duplicate detection and is
 * never displayed. Where no number exists we store NULL and fall back to an
 * internal `historical_reference` UUID that is never shown as an invoice number.
 *
 * CROSS-SHOP INTEGRITY. Every child link is a COMPOSITE foreign key
 * `(child_id, shop_id) -> parent (id, shop_id)`, following the precedent set by
 * 2026_09_07_000000_restore_product_item_referential_integrity. A row in shop A
 * cannot reference a parent, customer, item or revision in shop B — enforced by
 * PostgreSQL, not by application code.
 *
 * Two redundant UNIQUE (id, shop_id) keys are added to `customers` and `items`
 * so they can be referenced compositely. Both are trivially satisfied (id is
 * already the PK); no data is read, rewritten or moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addCompositeShopKeysToLinkTargets();
        $this->createProfiles();
        $this->createBatches();
        $this->createDocuments();
        $this->createLines();
        $this->createRows();
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_import_rows');
        Schema::dropIfExists('historical_sales_lines');
        Schema::dropIfExists('historical_sales_documents');
        Schema::dropIfExists('historical_import_batches');
        Schema::dropIfExists('historical_import_profiles');

        foreach (['customers_id_shop_id_unique', 'items_id_shop_id_unique'] as $constraint) {
            $table = str_starts_with($constraint, 'customers') ? 'customers' : 'items';
            if ($this->constraintExists($constraint)) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }
    }

    /**
     * Make `customers` and `items` referenceable by composite (id, shop_id) FKs.
     * `id` is the primary key, so the pair is already unique — this only exposes
     * it as a referenceable key. Purely additive.
     */
    private function addCompositeShopKeysToLinkTargets(): void
    {
        if (! $this->constraintExists('customers_id_shop_id_unique')) {
            DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_id_shop_id_unique UNIQUE (id, shop_id)');
        }

        if (! $this->constraintExists('items_id_shop_id_unique')) {
            DB::statement('ALTER TABLE items ADD CONSTRAINT items_id_shop_id_unique UNIQUE (id, shop_id)');
        }
    }

    /**
     * Per-shop description of ONE source system's file layout. Batch 1 stores the
     * configuration only — no parsing or mapping behaviour is implemented yet.
     */
    private function createProfiles(): void
    {
        Schema::create('historical_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name');
            $table->string('source_system');
            // How one document maps onto source rows: one row per line, a header
            // row followed by detail rows, or header totals only (no line detail).
            $table->string('layout_type')->default('single_row_per_line');
            $table->jsonb('mapping')->default(DB::raw("'{}'::jsonb"));
            // Explicit, never guessed: 03/04/2023 is ambiguous and guessing it
            // silently shifts a year's worth of revenue between months.
            $table->string('date_format');
            $table->jsonb('tax_defaults')->nullable();
            $table->jsonb('making_defaults')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'name'], 'historical_import_profiles_shop_name_unique');
            $table->index(['shop_id', 'is_active']);
        });

        DB::statement(
            'ALTER TABLE historical_import_profiles '
            . 'ADD CONSTRAINT historical_import_profiles_id_shop_id_unique UNIQUE (id, shop_id)'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_profiles
            ADD CONSTRAINT historical_import_profiles_layout_type_check
            CHECK (layout_type IN ('single_row_per_line', 'header_detail', 'header_only'))
        SQL);
    }

    /**
     * One import run. Owns the lifecycle and the publish/rollback boundary.
     *
     * Unlike `onboarding_batches` there is NO one-active-batch-per-shop index:
     * a shop legitimately migrates several years from several source files, and
     * those drafts can coexist. The publish claim is what must be exclusive, and
     * that is enforced by the atomic draft/review -> publishing transition.
     */
    private function createBatches(): void
    {
        Schema::create('historical_import_batches', function (Blueprint $table) {
            $table->id();
            // RESTRICT, not CASCADE: historical documents are retained evidence
            // and must not be destroyable by a single Shop::delete() cascade.
            // Same reasoning as 2026_08_25_010000 for the Dhiran financial tables.
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_import_profile_id')->nullable();
            $table->string('label')->nullable();
            $table->string('source_system')->nullable();
            $table->string('source_file_name')->nullable();
            $table->string('status')->default('draft'); // draft|review|publishing|published|cancelled
            // The shop's go-live date. A document dated after it is not rejected
            // (POS outage, forgotten bill, migration backlog) but must be
            // acknowledged per-document with a recorded reason.
            $table->date('cutover_date')->nullable();
            $table->jsonb('totals_snapshot')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('document_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE historical_import_batches '
            . 'ADD CONSTRAINT historical_import_batches_id_shop_id_unique UNIQUE (id, shop_id)'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_batches
            ADD CONSTRAINT historical_import_batches_status_check
            CHECK (status IN ('draft', 'review', 'publishing', 'published', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_batches
            ADD CONSTRAINT historical_import_batches_published_audit_check
            CHECK (status <> 'published' OR published_at IS NOT NULL)
        SQL);

        DB::statement(
            'CREATE INDEX historical_import_batches_profile_id_shop_id_index '
            . 'ON historical_import_batches (historical_import_profile_id, shop_id)'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_batches
            ADD CONSTRAINT historical_import_batches_profile_id_shop_id_foreign
            FOREIGN KEY (historical_import_profile_id, shop_id)
            REFERENCES historical_import_profiles (id, shop_id) ON DELETE NO ACTION
        SQL);
    }

    /**
     * The canonical historical document. Record-only: creating one posts nothing
     * to any ledger, moves no stock, consumes no counter and sends no notification.
     */
    private function createDocuments(): void
    {
        Schema::create('historical_sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_import_batch_id');

            // Internal database identifier ONLY. Never presented to a customer,
            // never presented as "the invoice number" — it exists so a document
            // with no printed number is still addressable.
            $table->uuid('historical_reference');

            // --- Original document identity (statutory evidence) -------------
            // Stored byte-for-byte as supplied. NULL when the source truly has
            // no number; we never fabricate one.
            $table->string('original_document_number')->nullable();
            // Search / duplicate detection ONLY. Never displayed.
            $table->string('original_document_number_normalized')->nullable();
            $table->string('document_series')->nullable();
            $table->string('document_type')->default('sale_invoice');
            $table->date('document_date');
            $table->string('financial_year'); // e.g. 2023-24

            // --- Provenance ---------------------------------------------------
            $table->string('source_system')->nullable();
            $table->string('source_reference')->nullable(); // file / sheet / row trail

            // --- Party (link optional, snapshot mandatory) ---------------------
            $table->unsignedBigInteger('customer_id')->nullable();
            // Immutable. Survives unlinking: what the original bill said stays
            // what the original bill said, even if the linked customer is edited.
            $table->jsonb('customer_snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->string('place_of_supply')->nullable();
            $table->string('customer_gstin')->nullable();
            $table->string('shop_gstin_snapshot')->nullable();

            // --- Tax (analytical only; never feeds GSTR-1/3B) -------------------
            $table->string('tax_mode')->default('unknown');          // inclusive|exclusive|unknown|not_applicable
            $table->string('tax_completeness')->default('unknown');  // complete|summary_only|unknown|not_applicable
            $table->jsonb('tax_snapshot')->nullable();

            // --- Money. NULL means "the source did not tell us", never zero. ----
            $table->decimal('taxable_amount', 18, 2)->nullable();
            $table->decimal('discount_snapshot', 18, 2)->nullable();
            $table->decimal('rounding_snapshot', 18, 2)->nullable();
            $table->decimal('grand_total', 18, 2);
            $table->decimal('paid_amount_snapshot', 18, 2)->nullable();
            $table->decimal('outstanding_amount_snapshot', 18, 2)->nullable();
            $table->decimal('metal_value', 18, 2)->nullable();
            $table->decimal('stone_value', 18, 2)->nullable();

            // --- Making / labour: original wording AND normalized meaning ------
            $table->string('making_label_original')->nullable(); // "Labour", "Majuri", "MC"
            $table->string('making_category')->nullable();       // making|labour|wastage|hallmarking|other|unknown
            $table->string('making_basis')->nullable();          // per_gram|percent|flat|included|unknown
            $table->decimal('making_amount', 18, 2)->nullable();
            $table->string('making_value_original')->nullable();  // "12%", "450/gm" as printed

            // --- Reconciliation with the opening-balance migration path ---------
            // customer_opening_balances is authoritative for current receivables.
            // paid/outstanding here are DISPLAY-ONLY snapshots of the old bill.
            $table->boolean('opening_balance_overlap')->default(false);
            $table->boolean('cutover_warning_acknowledged')->default(false);
            $table->text('cutover_warning_reason')->nullable();

            $table->jsonb('raw_payload')->nullable(); // normalized source-row audit trail

            // --- Lifecycle ------------------------------------------------------
            $table->string('status')->default('draft'); // draft|published|superseded|void
            $table->unsignedBigInteger('revises_document_id')->nullable();
            $table->unsignedBigInteger('superseded_by_document_id')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            // Content identity: catches the same physical bill arriving from a
            // different file or a different source system. Excludes source_system
            // by construction (see HistoricalDocumentFingerprint).
            $table->char('content_fingerprint', 64);
            // Set only when an operator explicitly confirms two content-identical
            // documents are genuinely distinct bills. Feeds the fingerprint so the
            // confirmation is durable and auditable, never a silent suffix.
            $table->string('duplicate_override_key')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'document_date']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'original_document_number_normalized'], 'historical_docs_shop_number_index');
            $table->index(['historical_import_batch_id']);
        });

        DB::statement(
            'ALTER TABLE historical_sales_documents '
            . 'ADD CONSTRAINT historical_sales_documents_id_shop_id_unique UNIQUE (id, shop_id)'
        );

        $this->addDocumentForeignKeys();
        $this->addDocumentChecks();
        $this->addDocumentDuplicateIdentity();
    }

    private function addDocumentForeignKeys(): void
    {
        $links = [
            // [child column, referenced table, index name, fk name]
            ['historical_import_batch_id', 'historical_import_batches', 'historical_docs_batch_shop_index', 'historical_docs_batch_shop_foreign'],
            ['customer_id',                'customers',                'historical_docs_customer_shop_index', 'historical_docs_customer_shop_foreign'],
            ['revises_document_id',        'historical_sales_documents', 'historical_docs_revises_shop_index', 'historical_docs_revises_shop_foreign'],
            ['superseded_by_document_id',  'historical_sales_documents', 'historical_docs_superseded_shop_index', 'historical_docs_superseded_shop_foreign'],
        ];

        foreach ($links as [$column, $parent, $indexName, $fkName]) {
            DB::statement("CREATE INDEX {$indexName} ON historical_sales_documents ({$column}, shop_id)");
            // NO ACTION (not RESTRICT): non-deferrable checks run at statement end,
            // so a whole-shop cascade delete settles instead of tripping on
            // intermediate state — same reasoning as the items->products FK.
            DB::statement(
                "ALTER TABLE historical_sales_documents ADD CONSTRAINT {$fkName} "
                . "FOREIGN KEY ({$column}, shop_id) REFERENCES {$parent} (id, shop_id) ON DELETE NO ACTION"
            );
        }
    }

    private function addDocumentChecks(): void
    {
        $checks = [
            'historical_docs_document_type_check' =>
                // Constrained but safely expandable: R1 is completed sales only.
                "document_type IN ('sale_invoice')",
            'historical_docs_status_check' =>
                "status IN ('draft', 'published', 'superseded', 'void')",
            'historical_docs_tax_mode_check' =>
                "tax_mode IN ('inclusive', 'exclusive', 'unknown', 'not_applicable')",
            'historical_docs_tax_completeness_check' =>
                "tax_completeness IN ('complete', 'summary_only', 'unknown', 'not_applicable')",
            'historical_docs_making_basis_check' =>
                "making_basis IS NULL OR making_basis IN ('per_gram', 'percent', 'flat', 'included', 'unknown')",
            // A completed sale never has negative money. A future document type
            // that legitimately does (credit note) must relax this deliberately.
            'historical_docs_non_negative_check' => <<<'SQL'
                grand_total >= 0
                AND (taxable_amount IS NULL OR taxable_amount >= 0)
                AND (paid_amount_snapshot IS NULL OR paid_amount_snapshot >= 0)
                AND (outstanding_amount_snapshot IS NULL OR outstanding_amount_snapshot >= 0)
                AND (metal_value IS NULL OR metal_value >= 0)
                AND (stone_value IS NULL OR stone_value >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
                SQL,
            // Reconcile ONLY when the source told us both halves. Unknown stays
            // unknown; we never invent a zero to make the arithmetic close.
            'historical_docs_settlement_reconciliation_check' => <<<'SQL'
                paid_amount_snapshot IS NULL
                OR outstanding_amount_snapshot IS NULL
                OR ROUND(paid_amount_snapshot + outstanding_amount_snapshot, 2) = ROUND(grand_total, 2)
                SQL,
            'historical_docs_published_audit_check' =>
                "status NOT IN ('published', 'superseded') OR published_at IS NOT NULL",
            'historical_docs_void_audit_check' =>
                "status <> 'void' OR (void_reason IS NOT NULL AND voided_at IS NOT NULL)",
            // A cutover warning without a recorded reason is not an acknowledgement.
            'historical_docs_cutover_ack_check' =>
                'cutover_warning_acknowledged = false OR cutover_warning_reason IS NOT NULL',
            'historical_docs_no_self_revision_check' =>
                '(revises_document_id IS NULL OR revises_document_id <> id) '
                . 'AND (superseded_by_document_id IS NULL OR superseded_by_document_id <> id)',
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE historical_sales_documents ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * Duplicate protection.
     *
     * NULL SEMANTICS ARE THE WHOLE PROBLEM HERE. A plain
     * UNIQUE (shop_id, financial_year, document_type, document_series,
     * original_document_number_normalized) is worthless: PostgreSQL treats every
     * NULL as distinct, and `document_series` is nullable for most shops, so a
     * shop with no series would get unlimited duplicates of the same invoice
     * number. Both indexes below therefore fold NULL into a non-NULL sentinel
     * with COALESCE, and the number index is partial so unnumbered documents fall
     * through to the fingerprint index instead.
     *
     * NULL IS NOT THE ONLY BYPASS. `document_series` is operator-entered free
     * text, so `A`, `a` and `A ` are three distinct index keys and the same bill
     * could be imported three times under "different" series. upper() and btrim()
     * are both IMMUTABLE in PostgreSQL and close that hole inside the index
     * itself, which is the only enforcement point that cannot be forgotten by a
     * future write path. Series remains a real distinction — `A` and `B` are
     * still separate — it just stops being case- and padding-sensitive.
     *
     * Voided and superseded documents are excluded from both: correcting a
     * mistake must not permanently burn the invoice number or the content.
     */
    private function addDocumentDuplicateIdentity(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historical_docs_number_identity_unique
            ON historical_sales_documents (
                shop_id,
                financial_year,
                document_type,
                COALESCE(upper(btrim(document_series)), ''),
                original_document_number_normalized
            )
            WHERE original_document_number_normalized IS NOT NULL
              AND status NOT IN ('void', 'superseded')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historical_docs_content_fingerprint_unique
            ON historical_sales_documents (shop_id, content_fingerprint)
            WHERE status NOT IN ('void', 'superseded')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX historical_docs_reference_unique
            ON historical_sales_documents (shop_id, historical_reference)
        SQL);
    }

    /**
     * Line detail. Optional: header-only documents (a total with no line
     * breakdown) are a normal, supported historical shape.
     *
     * Note this table carries `shop_id` — unlike the operational `invoice_items`,
     * which has neither shop_id nor a global scope. That absence is exactly why
     * historical data must not share the operational tables.
     */
    private function createLines(): void
    {
        Schema::create('historical_sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_sales_document_id');
            $table->unsignedInteger('line_number');

            // Link is optional and advisory. We never create a fake inventory
            // item, and linking never mutates current stock.
            $table->unsignedBigInteger('item_id')->nullable();
            $table->jsonb('item_snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->string('source_sku')->nullable();
            $table->string('source_description')->nullable();
            $table->string('hsn_snapshot')->nullable();

            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('gross_weight', 12, 3)->nullable();
            $table->decimal('net_weight', 12, 3)->nullable();
            $table->decimal('stone_weight', 12, 3)->nullable();
            $table->string('purity_snapshot')->nullable();
            $table->string('metal_snapshot')->nullable();
            $table->jsonb('stone_snapshot')->nullable();

            $table->string('making_label_original')->nullable();
            $table->string('making_category')->nullable();
            $table->string('making_basis')->nullable();
            $table->decimal('making_amount', 18, 2)->nullable();
            $table->string('making_value_original')->nullable();

            $table->decimal('rate_snapshot', 18, 2)->nullable();
            $table->decimal('line_total', 18, 2);
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['historical_sales_document_id', 'line_number'], 'historical_lines_document_line_unique');
            $table->index(['shop_id']);
        });

        DB::statement(
            'CREATE INDEX historical_lines_document_shop_index '
            . 'ON historical_sales_lines (historical_sales_document_id, shop_id)'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_document_shop_foreign
            FOREIGN KEY (historical_sales_document_id, shop_id)
            REFERENCES historical_sales_documents (id, shop_id) ON DELETE CASCADE
        SQL);

        DB::statement('CREATE INDEX historical_lines_item_shop_index ON historical_sales_lines (item_id, shop_id)');
        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_item_shop_foreign
            FOREIGN KEY (item_id, shop_id)
            REFERENCES items (id, shop_id) ON DELETE NO ACTION
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_non_negative_check
            CHECK (
                line_total >= 0
                AND (quantity IS NULL OR quantity >= 0)
                AND (gross_weight IS NULL OR gross_weight >= 0)
                AND (net_weight IS NULL OR net_weight >= 0)
                AND (stone_weight IS NULL OR stone_weight >= 0)
                AND (making_amount IS NULL OR making_amount >= 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_sales_lines
            ADD CONSTRAINT historical_lines_making_basis_check
            CHECK (making_basis IS NULL OR making_basis IN ('per_gram', 'percent', 'flat', 'included', 'unknown'))
        SQL);
    }

    /**
     * Editable staging for one batch's source rows. Rows are freely correctable
     * and deletable while the batch is unpublished; the audit payload is retained
     * after publish so a published document can always be traced to its source.
     */
    private function createRows(): void
    {
        Schema::create('historical_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('historical_import_batch_id');
            // NOT NULL with a '' default so the uniqueness below is not defeated
            // by NULL-distinct semantics on single-sheet files.
            $table->string('source_sheet')->default('');
            $table->unsignedInteger('source_row_number');
            // Which rows collapse into one document (invoice number, or a
            // synthetic key when the source has none).
            $table->string('grouping_key')->nullable();
            $table->jsonb('original_payload');
            $table->jsonb('normalized_payload')->nullable();
            $table->string('severity')->default('ok');            // ok|info|warning|error
            $table->string('validation_status')->default('pending'); // pending|valid|invalid|skipped
            $table->jsonb('messages')->nullable();
            $table->unsignedBigInteger('historical_sales_document_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['historical_import_batch_id', 'source_sheet', 'source_row_number'],
                'historical_rows_batch_sheet_row_unique'
            );
            $table->index(['shop_id', 'validation_status']);
            $table->index(['historical_import_batch_id', 'grouping_key'], 'historical_rows_batch_group_index');
        });

        DB::statement(
            'CREATE INDEX historical_rows_batch_shop_index '
            . 'ON historical_import_rows (historical_import_batch_id, shop_id)'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_rows
            ADD CONSTRAINT historical_rows_batch_shop_foreign
            FOREIGN KEY (historical_import_batch_id, shop_id)
            REFERENCES historical_import_batches (id, shop_id) ON DELETE CASCADE
        SQL);

        DB::statement(
            'CREATE INDEX historical_rows_document_shop_index '
            . 'ON historical_import_rows (historical_sales_document_id, shop_id)'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_rows
            ADD CONSTRAINT historical_rows_document_shop_foreign
            FOREIGN KEY (historical_sales_document_id, shop_id)
            REFERENCES historical_sales_documents (id, shop_id) ON DELETE NO ACTION
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_rows
            ADD CONSTRAINT historical_rows_severity_check
            CHECK (severity IN ('ok', 'info', 'warning', 'error'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE historical_import_rows
            ADD CONSTRAINT historical_rows_validation_status_check
            CHECK (validation_status IN ('pending', 'valid', 'invalid', 'skipped'))
        SQL);
    }

    private function constraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ?',
            [$name]
        ) !== null;
    }
};
