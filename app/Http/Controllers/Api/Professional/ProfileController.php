<?php

namespace App\Http\Controllers\Api\Professional;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\UpdateProfessionalProfileRequest;
use App\Http\Resources\ProfessionalProfileResource;
use App\Models\ProfessionalProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    use InteractsWithProfessionalProfile;

    /**
     * The professional's own profile, or null before they have set one up.
     */
    public function show(Request $request): ProfessionalProfileResource|JsonResponse
    {
        $profile = $request->user()->professionalProfile;

        /*
         * A professional who has just registered has no profile yet. Returning
         * an explicit null rather than a 404 lets the frontend decide between
         * the setup form and the editor from one request, instead of treating a
         * 404 as a normal state.
         */
        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        return ProfessionalProfileResource::make($this->loadProfileForEditor($profile));
    }

    /**
     * Create or update the profile.
     *
     * An upsert rather than separate create and update endpoints: the setup
     * form and the editor post the same fields, and the professional should not
     * have to know which one they are in for the save to work.
     */
    public function update(UpdateProfessionalProfileRequest $request): ProfessionalProfileResource
    {
        $user = $request->user();
        $data = $request->validated();

        $profile = DB::transaction(function () use ($user, $data): ProfessionalProfile {
            $profile = ProfessionalProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $data['business_name'],
                    'about' => $data['about'] ?? null,
                    'experience_years' => $data['experience_years'] ?? 0,
                    'phone' => $data['phone'] ?? null,
                    'base_location_id' => $data['base_location_id'] ?? null,
                ],
            );

            /*
             * Service areas are replaced wholesale, matching how the editor
             * sends them: what the professional sees ticked is what they cover.
             * Syncing individual areas would risk the two drifting apart if a
             * save failed halfway.
             */
            $profile->serviceAreas()->sync($data['service_area_ids'] ?? []);

            return $profile;
        });

        return ProfessionalProfileResource::make($this->loadProfileForEditor($profile));
    }

    /**
     * The figures behind the /pro landing screen (spec §4, §17).
     *
     * Deliberately limited to what the profile itself owns. Booking and payment
     * statistics are added to this payload by the phases that introduce them,
     * so the endpoint stays the single call the dashboard makes.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());

        $profile->loadCount(['documents', 'portfolioItems']);

        return response()->json([
            'data' => [
                'verification_status' => $profile->verification_status->value,
                'verification_label' => $profile->verification_status->label(),
                'is_verified' => $profile->isVerified(),

                // Whether customers can currently find them. An unverified
                // profile is complete but invisible, and the dashboard has to
                // say so plainly or the professional will assume it is live.
                'is_listed' => $profile->verification_status->isVisibleToCustomers(),
                'rating_avg' => (float) $profile->rating_avg,
                'rating_count' => $profile->rating_count,
                'completed_jobs_count' => $profile->completed_jobs_count,
                'active_services_count' => $profile->services()->where('is_active', true)->count(),
                'service_areas_count' => $profile->serviceAreas()->count(),
                'portfolio_items_count' => $profile->portfolio_items_count,
                'documents_count' => $profile->documents_count,
                'pending_documents_count' => $profile->documents()
                    ->where('status', VerificationStatus::Pending->value)
                    ->count(),
            ],
        ]);
    }
}
