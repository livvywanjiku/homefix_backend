<?php

namespace App\Http\Resources;

use App\Models\ProfessionalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A professional as they appear on a search result card.
 *
 * Deliberately not `ProfessionalProfileResource`. That resource is built for one
 * profile in depth — it carries the portfolio, the weekly availability and the
 * exception list — and reusing it for a page of twenty results would load all of
 * that per row for markup that shows none of it.
 *
 * `distance_km` is populated by the search controller when a radius filter is in
 * play; it is absent otherwise rather than null, because "no distance was asked
 * for" and "the distance is unknown" are different things to the card.
 *
 * @mixin ProfessionalProfile
 */
class ProfessionalCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,

            // A teaser, not the essay. The full text belongs on the profile page.
            'about_excerpt' => $this->about === null
                ? null
                : Str::limit(strip_tags($this->about), 160),

            'experience_years' => $this->experience_years,

            'verification_status' => $this->verification_status->value,
            'verification_label' => $this->verification_status->label(),
            'is_verified' => $this->isVerified(),

            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'completed_jobs_count' => $this->completed_jobs_count,

            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
            ]),

            'base_location' => LocationResource::make($this->whenLoaded('baseLocation')),

            // Just the towns, for the "covers Nakuru, Njoro" line on the card.
            'service_areas' => $this->whenLoaded(
                'serviceAreas',
                fn (): array => $this->serviceAreas
                    ->map(fn ($area): array => ['id' => $area->id, 'name' => $area->name])
                    ->all(),
            ),

            // The "from KSh 1,500" figure. Null means every service is priced on
            // inspection, which the card renders as "Quote".
            'from_price_cents' => $this->whenLoaded(
                'services',
                fn (): ?int => $this->lowestPriceCents(),
            ),
            'services_count' => $this->whenCounted('services'),

            /*
             * A few things this professional actually does, so a result card
             * says more than a name and a rating. Capped at three: the card is a
             * starting point, and the full list is one click away on the profile.
             */
            'top_services' => $this->whenLoaded('services', fn (): array => $this->services
                ->where('is_active', true)
                ->filter(fn ($offering): bool => $offering->service !== null)
                ->take(3)
                ->map(fn ($offering): array => [
                    'id' => $offering->service->id,
                    'name' => $offering->service->name,
                ])
                ->values()
                ->all()),

            'distance_km' => $this->when(
                isset($this->distance_km),
                fn (): ?float => $this->distance_km === null
                    ? null
                    : round((float) $this->distance_km, 1),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
