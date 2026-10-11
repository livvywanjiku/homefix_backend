<?php

namespace App\Http\Requests\ServiceRequests;

/**
 * Editing a posted job (spec §8).
 *
 * The same shape as posting one, minus the photos: images are added and removed
 * through their own endpoints so that re-saving the text of a request cannot
 * silently drop the pictures attached to it.
 */
class UpdateServiceRequestRequest extends StoreServiceRequestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['images'], $rules['images.*']);

        return $rules;
    }
}
