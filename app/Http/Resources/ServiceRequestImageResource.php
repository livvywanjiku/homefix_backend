<?php

namespace App\Http\Resources;

use App\Models\ServiceRequestImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceRequestImage
 */
class ServiceRequestImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,

            // Absolute, so the frontend never has to know where Laravel keeps
            // its public disk. Matches PortfolioImageResource.
            'url' => $this->path === null ? null : url('storage/'.ltrim($this->path, '/')),
            'original_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
        ];
    }
}
