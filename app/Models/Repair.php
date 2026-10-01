<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Repair extends Model
{
    use BelongsToShop;

    /** Metal types a repair item can be made of. */
    public const METAL_TYPES = ['gold', 'silver', 'platinum', 'other'];

    public const DEFAULT_METAL_TYPE = 'gold';

    /**
     * Metal-aware purity presets, keyed by metal type. Each entry is
     * { value, label } so clients (web form + mobile) can render the right
     * scale (gold karats vs silver/platinum fineness) without hardcoding.
     * 'other' carries no presets — purity is optional/free for those.
     *
     * @var array<string, array<int, array{value: string, label: string}>>
     */
    public const PURITY_OPTIONS = [
        'gold' => [
            ['value' => '24', 'label' => '24K'],
            ['value' => '22', 'label' => '22K'],
            ['value' => '21', 'label' => '21K'],
            ['value' => '18', 'label' => '18K'],
            ['value' => '14', 'label' => '14K'],
        ],
        'silver' => [
            ['value' => '999', 'label' => '999'],
            ['value' => '925', 'label' => '925'],
            ['value' => '900', 'label' => '900'],
        ],
        'platinum' => [
            ['value' => '999', 'label' => '999'],
            ['value' => '950', 'label' => '950'],
            ['value' => '900', 'label' => '900'],
        ],
        'other' => [],
    ];

    protected $fillable = [
        'customer_id',
        'item_description',
        'description',
        'image_path',
        'image',
        'due_date',
        'metal_type',
        'gross_weight',
        'purity',
        'estimated_cost',
        'final_cost',
        'status',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'due_date' => 'date',
    ];

    /**
     * purity_label is a derived, metal-aware display string (karat suffix for
     * gold, plain fineness otherwise). Appended so every serialized repair
     * (index/show/store) carries it — mobile renders it verbatim and never has
     * to reimplement the karat-vs-millesimal formatting.
     */
    protected $appends = ['purity_label'];

    /** Display labels for each metal type. */
    public const METAL_LABELS = [
        'gold'     => 'Gold',
        'silver'   => 'Silver',
        'platinum' => 'Platinum',
        'other'    => 'Other',
    ];

    /**
     * Upper bound for a valid purity value given a metal type. Gold is karat
     * based (≤24); silver/platinum are fineness based (≤999). Used by both the
     * web and mobile controllers so the rule lives in one place. Unknown/other
     * metals accept the wider fineness range.
     */
    public static function maxPurityFor(?string $metalType): float
    {
        return $metalType === 'gold' ? 24 : 999;
    }

    /** The unit purity is expressed in for a metal: karat for gold, millesimal
     *  fineness for silver/platinum, null for "other" (free entry / N-A). */
    public static function purityUnitFor(string $metalType): ?string
    {
        return match ($metalType) {
            'gold'              => 'karat',
            'silver', 'platinum' => 'millesimal',
            default            => null,
        };
    }

    /**
     * Consolidated, server-driven metal catalogue for the mobile picker. Each
     * entry carries value, label, max_purity (== maxPurityFor(), numeric so the
     * client cap matches the server cap exactly), purity_unit, and the string
     * purity presets. Single source of truth for GET /repairs/options.
     *
     * @return array<int, array{value:string,label:string,max_purity:float,purity_unit:?string,purities:array}>
     */
    public static function metalsCatalog(): array
    {
        return array_map(static function (string $metal): array {
            return [
                'value'       => $metal,
                'label'       => self::METAL_LABELS[$metal] ?? ucfirst($metal),
                'max_purity'  => self::maxPurityFor($metal),
                'purity_unit' => self::purityUnitFor($metal),
                'purities'    => self::PURITY_OPTIONS[$metal] ?? [],
            ];
        }, self::METAL_TYPES);
    }

    /** Accessor backing the appended `purity_label` attribute. */
    public function getPurityLabelAttribute(): ?string
    {
        return $this->purityLabel();
    }

    /**
     * Human label for a stored purity given the item's metal type: gold shows a
     * karat suffix (22K), silver/platinum show fineness as-is (925), other shows
     * the raw value. Returns null when no purity is recorded.
     */
    public function purityLabel(): ?string
    {
        if ($this->purity === null || $this->purity === '') {
            return null;
        }

        $value = (float) $this->purity;

        if ($this->metal_type === 'gold') {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . 'K';
        }

        // Silver / platinum / other: fineness or plain number, no karat suffix.
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    protected static function booted(): void
    {
        static::creating(function (self $repair): void {
            if (empty($repair->repair_number) && !empty($repair->shop_id)) {
                $repair->repair_number = BusinessIdentifierService::nextCounter((int) $repair->shop_id, BusinessIdentifierService::KEY_REPAIR);
            }
        });

        static::saving(function (self $repair): void {
            if ($repair->status === 'delivered' && empty($repair->invoice_id)) {
                throw new \DomainException('A repair cannot be marked delivered without a linked invoice. Use the Bill flow.');
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function resolveImagePath(): ?string
    {
        $path = $this->image_path ?: $this->image;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * A repair photo is a customer's attachment: it lives on the private disk
     * and is only ever handed out through a route that checks who is asking.
     */
    public const IMAGE_DISK = 'local';

    /** Where new photos are written. */
    public static function imageWriteDisk(): string
    {
        return config('filesystems.default') === 's3' ? 's3' : self::IMAGE_DISK;
    }

    /** Where photos may be found: the private disk, and the public one until `repairs:relocate-images` has run. */
    public static function imageReadDisks(): array
    {
        return array_values(array_unique([self::imageWriteDisk(), self::IMAGE_DISK, 'public']));
    }

    /** The disk that holds this repair's photo, or null. */
    public function imageDisk(): ?string
    {
        $path = $this->resolveImagePath();
        if ($path === null || preg_match('/^https?:\/\//i', $path) === 1) {
            return null;
        }
        foreach (self::imageReadDisks() as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /** For a signed-in browser: the staff route (session, repairs.view, this shop). */
    public function resolveImageUrl(): ?string
    {
        return $this->resolveImagePath() === null ? null : route('repairs.image', $this);
    }

    /**
     * For the mobile app, which loads an image with a plain request and no
     * Authorization header: a link to this one photo that stops working after
     * fifteen minutes. It is only ever returned by the authenticated,
     * shop-scoped repairs API.
     */
    public function signedImageUrl(): ?string
    {
        return $this->resolveImagePath() === null ? null
            : \Illuminate\Support\Facades\URL::temporarySignedRoute('mobile.repairs.image', now()->addMinutes(15), ['repair' => $this->id]);
    }

    /** The photo as a response, from whichever disk holds it. Never cached by anything in between. */
    public function imageResponse()
    {
        $disk = $this->imageDisk();
        abort_if($disk === null, 404);

        return Storage::disk($disk)->response($this->resolveImagePath(), null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Remove the photo from every disk it may be on. */
    public function deleteImageFile(): void
    {
        $path = $this->resolveImagePath();
        if ($path === null || preg_match('/^https?:\/\//i', $path) === 1) {
            return;
        }
        foreach (self::imageReadDisks() as $disk) {
            Storage::disk($disk)->delete($path);
        }
    }
}
