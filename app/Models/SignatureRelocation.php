<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded, digest-verified move of a signature file from one
 * app-controlled disk to another. Written by signatures:relocate, read by
 * InvoiceSignatureRenderer.
 *
 * NOT an ImmutableLedger. These rows describe where bytes physically are, and
 * that is a fact about the filesystem that a future approved relocation may
 * legitimately supersede. The immutable things here are the render snapshots,
 * which this table exists to keep readable without rewriting them.
 *
 * BelongsToShop so a relocation row can never be read across tenants by an
 * unscoped query. The renderer additionally filters on shop_id explicitly,
 * because the global scope is a no-op under runningInConsole() and a signature
 * is resolved from queued and console contexts too.
 */
class SignatureRelocation extends Model
{
    use BelongsToShop;

    protected $table = 'signature_relocations';

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'path',
        'source_disk',
        'target_disk',
        'sha256',
        'bytes',
        'relocated_at',
    ];

    protected $casts = [
        'shop_id'      => 'integer',
        'bytes'        => 'integer',
        'relocated_at' => 'datetime',
    ];
}
