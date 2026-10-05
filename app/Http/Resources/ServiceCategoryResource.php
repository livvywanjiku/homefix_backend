<?php

namespace App\Http\Resources;

use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceCategory
 */
class ServiceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,

            // Present only when the caller eager-loaded it, so a category list
            // does not quietly issue one query per row.
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'services_count' => $this->whenCounted('services'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
