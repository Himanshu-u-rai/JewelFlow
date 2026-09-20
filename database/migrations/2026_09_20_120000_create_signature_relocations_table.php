<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit finding S3-04 — the relocation ledger.
 *
 * WHY A TABLE AND NOT A RULE
 * -------------------------
 * A finalized invoice's render snapshot is immutable, so it keeps naming the
 * disk its signature was on when the bill was signed. Relocation moves those
 * bytes from the web-served public tree to the private one WITHOUT rewriting
 * those snapshots — rewriting them is exactly what the immutability finding
 * forbids. That leaves every pre-relocation snapshot pointing at a disk the
 * file is no longer on.
 *
 * The first attempt at bridging that gap was a rule: try the recorded disk,
 * then every other allowed disk, serve whatever turns up. Its justification was
 * that Str::ulid() filenames are unique, so a path names one and only one set
 * of bytes wherever it lives.
 *
 * That reasoning does not hold. ULID uniqueness describes the NAMES a generator
 * emits. It is not a mapping from a historical reference to a verified copy,
 * and it establishes none of the three things the fallback actually needed:
 * that the destination bytes are the same bytes, that the path belongs to the
 * shop asking for it, or that falling back is safe in both directions. It is
 * not safe in both directions — a private reference resolving on the public
 * tree would serve the very copy relocation exists to retire.
 *
 * So the mapping is recorded rather than inferred. signatures:relocate writes
 * one row per file it copies, carrying the digest it verified at the
 * destination; InvoiceSignatureRenderer follows a relocation only when a row
 * vouches for it AND the bytes still hash to what was recorded. No row, no
 * fallback.
 *
 * SCOPE, HONESTLY
 * This covers files relocation actually moved. Superseded signature versions
 * referenced only by snapshots are reported by `--orphans` and are NOT moved,
 * so they need no rows yet. Bytes already lost before any of this shipped
 * cannot be recorded retroactively and cannot be reconstructed.
 *
 * Purely additive: a new table no deployed code reads or writes. Safe to apply
 * while baseline 018b3d8 is serving — unlike the three disk-column migrations,
 * which are not (see docs/runbooks/signature-migration-release-order.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('signature_relocations')) {
            return;
        }

        Schema::create('signature_relocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');

            // The relative storage path, identical on both disks. Relocation
            // copies to the SAME path, which is what lets an immutable snapshot
            // keep naming it.
            $table->string('path', 512);

            $table->string('source_disk', 20);
            $table->string('target_disk', 20);

            // sha256 of the source, re-verified at the destination before this
            // row was written. The renderer re-checks it at read time, so a
            // later overwrite is caught rather than served.
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');

            $table->timestamp('relocated_at')->useCurrent();

            // Makes a re-run of an interrupted relocation idempotent instead of
            // accumulating conflicting mappings for the same file.
            $table->unique(['shop_id', 'path', 'source_disk'], 'signature_relocations_unique_move');

            // The renderer's lookup: shop + path + source disk.
            $table->index(['shop_id', 'source_disk'], 'signature_relocations_shop_source_idx');

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_relocations');
    }
};
