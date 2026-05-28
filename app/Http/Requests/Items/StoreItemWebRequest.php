<?php

namespace App\Http\Requests\Items;

/**
 * Web-flavoured item-creation request. Adds multipart image uploads
 * (single primary + gallery) to the shared retailer rule set.
 */
final class StoreItemWebRequest extends StoreItemRequest
{
    private const MAX_ITEM_GALLERY_IMAGES = 4;
    private const ITEM_GALLERY_IMAGE_MIMES = 'jpg,jpeg,png,gif,webp,avif,bmp';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'image' => 'nullable|file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
            'images' => 'nullable|array|max:' . self::MAX_ITEM_GALLERY_IMAGES,
            'images.*' => 'file|mimes:' . self::ITEM_GALLERY_IMAGE_MIMES . '|max:5120',
        ]);
    }
}
