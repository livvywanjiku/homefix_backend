<?php

namespace App\Http\Resources;

use App\Models\PortfolioImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortfolioImage
 */
class PortfolioImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,

            // Absolute URL so the frontend never has to know where Laravel keeps
            // its public disk.
            'url' => $this->path === null ? null : url('storage/'.ltrim($this->path, '/')),
            'caption' => $this->caption,
            'is_cover' => $this->is_cover,
            'sort_order' => $this->sort_order,
        ];
    }
}
