<?php

namespace App\Http\Resources;

use App\Models\AvailabilityException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailabilityException
 */
class AvailabilityExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blocked_on' => $this->blocked_on?->toDateString(),
            'reason' => $this->reason,
        ];
    }
}
