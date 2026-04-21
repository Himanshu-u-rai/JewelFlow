<?php

namespace App\Data\Mobile;

use App\Models\Vendor;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VendorData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $contact_person,
        public ?string $mobile,
        public ?string $email,
        public ?string $address,
        public ?string $city,
        public ?string $state,
        public ?string $gst_number,
        public ?string $notes,
        public bool $is_active,
        public int $items_count,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Vendor $vendor, ?int $itemsCount = null): self
    {
        return new self(
            id: (int) $vendor->id,
            name: (string) $vendor->name,
            contact_person: $vendor->contact_person,
            mobile: $vendor->mobile,
            email: $vendor->email,
            address: $vendor->address,
            city: $vendor->city,
            state: $vendor->state,
            gst_number: $vendor->gst_number,
            notes: $vendor->notes,
            is_active: (bool) $vendor->is_active,
            items_count: (int) ($itemsCount ?? $vendor->items_count ?? 0),
            created_at: optional($vendor->created_at)?->toIso8601String(),
            updated_at: optional($vendor->updated_at)?->toIso8601String(),
        );
    }
}
