<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    use BelongsToShop;

    public const TYPE_CATALOG = 'catalog';
    public const TYPE_MANUFACTURE = 'manufacture';
    public const TYPE_STOCK = 'stock';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_STRICT = 'strict';
    public const MODE_ROW = 'row';

    protected $fillable = [
        'shop_id',
        'type',
        'status',
        'mode',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'processed_rows',
        'created_by',
        'file_path',
        'error_file_path',
        'preview_summary',
        'execution_summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'preview_summary' => 'array',
        'execution_summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $import): void {
            if ((empty($import->import_reference)) && !empty($import->shop_id)) {
                $seq = BusinessIdentifierService::nextCounter((int) $import->shop_id, BusinessIdentifierService::KEY_IMPORT);
                $import->import_reference = BusinessIdentifierService::formatImportReference($seq);
            }
        });
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
