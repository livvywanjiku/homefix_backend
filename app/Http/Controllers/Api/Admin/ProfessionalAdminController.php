<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProfessionalsRequest;
use App\Http\Requests\Admin\UpdateProfessionalStatusRequest;
use App\Http\Resources\ProfessionalProfileResource;
use App\Models\ProfessionalProfile;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The professional directory (spec §20).
 *
 * The verification *queue* lives in {@see ProfessionalVerificationController} and
 * answers "what needs deciding". This answers "who is on the platform and how are
 * they doing" — a different question, asked by a different screen, which is why
 * the two are not the same controller.
 */
class ProfessionalAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The professional list, filtered and paginated.
     */
    public function index(ListProfessionalsRequest $request): AnonymousResourceCollection
    {
        $profiles = ProfessionalProfile::query()
            ->with(['user:id,name,email,phone,avatar_path,status', 'baseLocation:id,name,region'])
            ->withCount(['services', 'documents'])
            ->when(
                $request->validated('verification_status'),
                fn ($query, string $status) => $query->where('verification_status', $status),
            )
            ->when(
                $request->validated('location_id'),
                fn ($query, int $location) => $query->where('base_location_id', $location),
            )
            ->when($request->validated('q'), function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('business_name', 'like', $like)
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like));
                });
            })
            ->when(
                $request->validated('sort'),
                fn ($query, string $sort) => match ($sort) {
                    'oldest' => $query->orderBy('created_at'),
                    'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
                    'jobs' => $query->orderByDesc('completed_jobs_count'),
                    'name' => $query->orderBy('business_name'),
                    default => $query->orderByDesc('created_at'),
                },
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ProfessionalProfileResource::collection($profiles);
    }

    /**
     * One professional's full record.
     */
    public function show(ProfessionalProfile $professional): ProfessionalProfileResource
    {
        return ProfessionalProfileResource::make($professional->load([
            'user:id,name,email,phone,avatar_path,status,created_at',
            'baseLocation',
            'documents',
            'services.service',
            'serviceAreas',
        ]));
    }

    /**
     * Suspend a listing, or put it back.
     */
    public function updateStatus(
        UpdateProfessionalStatusRequest $request,
        ProfessionalProfile $professional,
    ): JsonResponse {
        $action = $request->validated('action');
        $before = ['verification_status' => $professional->verification_status->value];

        if ($action === 'suspend') {
            $professional->forceFill([
                'verification_status' => VerificationStatus::Suspended,
                // `verified_at` is deliberately left standing: the record that
                // this professional *was* verified is the history a dispute needs.
                'verification_notes' => $request->validated('reason'),
            ])->save();

            $this->audit->recordChange(
                AdminAction::ProfessionalSuspended,
                $professional,
                $before,
                ['verification_status' => $professional->verification_status->value],
                "Suspended {$professional->business_name}",
            );
        } else {
            $professional->forceFill([
                'verification_status' => VerificationStatus::Verified,
                'verified_at' => $professional->verified_at ?? now(),
                'verification_notes' => null,
            ])->save();

            $this->audit->recordChange(
                AdminAction::ProfessionalActivated,
                $professional,
                $before,
                ['verification_status' => $professional->verification_status->value],
                "Reinstated {$professional->business_name}",
            );
        }

        return response()->json([
            'professional' => ProfessionalProfileResource::make($professional->refresh()->load('user')),
        ]);
    }

    /**
     * Remove a professional from the directory by suspending the account behind
     * the profile.
     *
     * Kept as its own action because the two are genuinely different: the
     * profile status governs whether the listing is visible, the account status
     * governs whether the person can sign in at all.
     */
    public function suspendAccount(ProfessionalProfile $professional): JsonResponse
    {
        $user = $professional->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'account' => 'This professional has no account attached.',
            ]);
        }

        $user->forceFill([
            'status' => \App\Enums\UserStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspended from the professional directory.',
        ])->save();

        $this->audit->record(
            AdminAction::UserSuspended,
            $user,
            "Suspended the account behind {$professional->business_name}",
        );

        return response()->json(['suspended' => true]);
    }
}
