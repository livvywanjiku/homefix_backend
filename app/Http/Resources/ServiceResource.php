<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_category_id' => $this->service_category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'category' => ServiceCategoryResource::make($this->whenLoaded('category')),

            // How many professionals have listed this service, when the caller
            // asked for the count. Present only when counted, so the public
            // catalogue does not pay for a query nobody reads.
            'professional_services_count' => $this->whenCounted('professionalServices'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
