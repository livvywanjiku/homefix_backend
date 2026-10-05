<?php

namespace App\Http\Resources;

use App\Models\ProfessionalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A professional, as seen by a customer browsing and by the professional
 * themselves.
 *
 * One resource covers both because the difference is a handful of fields, and
 * splitting it would mean two places to keep in step as the profile grows. The
 * owner-only fields are conditional on the authenticated viewer.
 *
 * @mixin ProfessionalProfile
 */
class ProfessionalProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $this->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'business_name' => $this->business_name,
            'about' => $this->about,
            'experience_years' => $this->experience_years,

            'verification_status' => $this->verification_status->value,
            'verification_label' => $this->verification_status->label(),
            'is_verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),

            // Cast to float because the column is a fixed-point decimal and
            // arrives as a string; the client renders it as a number.
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'completed_jobs_count' => $this->completed_jobs_count,

            // Never the account's email or the primary role — a public profile
            // exposes the trading name and avatar, nothing more. The exception
            // is an administrator reviewing the application, who has to be able
            // to reach the applicant.
            'user' => $this->whenLoaded('user', function () use ($isAdmin): array {
                $user = [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar_url' => $this->user->avatar_path,
                ];

                if ($isAdmin) {
                    $user['email'] = $this->user->email;
                    $user['phone'] = $this->user->phone;
                }

                return $user;
            }),

            'base_location' => LocationResource::make($this->whenLoaded('baseLocation')),
            'service_areas' => LocationResource::collection($this->whenLoaded('serviceAreas')),
            'services' => ProfessionalServiceResource::collection($this->whenLoaded('services')),
            'availabilities' => AvailabilityResource::collection($this->whenLoaded('availabilities')),
            'availability_exceptions' => AvailabilityExceptionResource::collection(
                $this->whenLoaded('availabilityExceptions'),
            ),
            'portfolio_items' => PortfolioItemResource::collection($this->whenLoaded('portfolioItems')),

            // Present only when the query asked for it, as the verification
            // queue does — "offers 4 services" is a reviewing signal.
            'services_count' => $this->whenCounted('services'),
            'documents_count' => $this->whenCounted('documents'),

            // The cheapest active service, so a card can show "from KSh 1,500".
            'from_price_cents' => $this->whenLoaded(
                'services',
                fn (): ?int => $this->lowestPriceCents(),
            ),

            // Business contact and review correspondence are for the owner and
            // the reviewing administrator only; customers reach a professional
            // through the request and messaging flows.
            'phone' => $this->when($isOwner || $isAdmin, $this->phone),
            'verification_notes' => $this->when($isOwner || $isAdmin, $this->verification_notes),
            'documents' => ProfessionalDocumentResource::collection($this->whenLoaded('documents')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
