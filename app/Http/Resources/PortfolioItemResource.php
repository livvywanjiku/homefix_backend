<?php

namespace App\Http\Resources;

use App\Models\PortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortfolioItem
 */
class PortfolioItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'completed_on' => $this->completed_on?->toDateString(),
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,

            'service' => ServiceResource::make($this->whenLoaded('service')),
            'location' => LocationResource::make($this->whenLoaded('location')),
            'images' => PortfolioImageResource::collection($this->whenLoaded('images')),

            /*
             * The card's thumbnail, resolved server-side.
             *
             * Which image is the cover is a rule (the flagged one, else the
             * first by sort order) rather than a fact, so it is decided once
             * here instead of being re-derived by every client.
             */
            'cover_url' => $this->whenLoaded('images', function (): ?string {
                $path = $this->coverImage()?->path;

                return $path === null ? null : url('storage/'.ltrim($path, '/'));
            }),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
