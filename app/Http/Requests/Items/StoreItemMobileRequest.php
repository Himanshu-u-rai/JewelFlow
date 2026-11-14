<?php

namespace App\Http\Requests\Items;

/**
 * Mobile-flavoured item-creation request. Adds base64 image transport
 * to the shared retailer rule set (the rest of the rules are inherited
 * verbatim from StoreItemRequest).
 */
final class StoreItemMobileRequest extends StoreItemRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'image_base64' => 'nullable|string',
            // A photo already sent through the upload flow (as the edit path and manufacturer create accept);
            // unlisted, validation dropped it and the photo was silently not attached.
            'image_upload_id' => 'nullable|string|max:80',
            // Up to 4 photos, the web form's gallery limit (ItemController::MAX_ITEM_GALLERY_IMAGES).
            'images_base64' => 'nullable|array|max:4',
            'images_base64.*' => 'string',
        ]);
    }
}
