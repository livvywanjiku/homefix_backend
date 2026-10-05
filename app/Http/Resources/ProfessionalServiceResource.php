<?php

namespace App\Http\Resources;

use App\Models\ProfessionalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProfessionalService
 */
class ProfessionalServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'description' => $this->description,
            'pricing_type' => $this->pricing_type->value,
            'pricing_label' => $this->pricing_type->label(),
            'price_min_cents' => $this->price_min_cents,
            'price_max_cents' => $this->price_max_cents,
            'is_active' => $this->is_active,

            // The catalogue entry this offering belongs to, so a card can show
            // "Plumbing · Leak repair" without a second request.
            'service' => ServiceResource::make($this->whenLoaded('service')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
