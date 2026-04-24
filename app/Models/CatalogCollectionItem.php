<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCollectionItem extends Model
{
    protected $fillable = [
        'collection_id',
        'item_id',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PublicCatalogCollection::class, 'collection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
