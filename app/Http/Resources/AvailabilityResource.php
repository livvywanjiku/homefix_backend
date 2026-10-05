<?php

namespace App\Http\Resources;

use App\Models\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Availability
 */
class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day_of_week' => $this->day_of_week,

            // Trimmed to HH:MM. The column is a TIME and the frontend renders a
            // time input, so the seconds component is noise.
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
        ];
    }
}
