<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Customer and professional accounts in the administration console (spec §20).
 *
 * Every suspension is written to the audit trail with the reason the
 * administrator gave. That is the whole point of the screen: suspending an
 * account is a consequential act against a real person, and the record of who
 * did it and why has to outlive the administrator's tenure.
 */
class UserAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The account list, filtered and paginated.
     */
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles:id,name')
            ->when(
                $request->validated('role'),
                fn ($query, string $role) => $query->role($role),
            )
            ->when(
                $request->validated('status'),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when($request->validated('q'), function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->when(
                $request->validated('sort'),
                fn ($query, string $sort) => match ($sort) {
                    'oldest' => $query->orderBy('created_at'),
                    'name' => $query->orderBy('name'),
                    // "Active" is the useful default when an administrator is
                    // looking for someone to talk to, and it is a different
                    // question from "newest".
                    'active' => $query->orderByDesc('status')->orderBy('name'),
                    default => $query->orderByDesc('created_at'),
                },
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * One account, with the profile it trades under when it is a professional.
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['roles:id,name', 'professionalProfile.baseLocation']);

        return response()->json([
            'user' => UserResource::make($user),
            'professional_profile' => $user->professionalProfile
                ? \App\Http\Resources\ProfessionalProfileResource::make($user->professionalProfile)
                : null,
            // Counted rather than listed: this is context for a decision about
            // the account, not a browsable history.
            'activity' => [
                'service_requests' => $user->serviceRequests()->count(),
                'bookings_as_customer' => $user->customerBookings()->count(),
                'bookings_as_professional' => $user->professionalBookings()->count(),
                'quotations' => $user->quotations()->count(),
            ],
        ]);
    }

    /**
     * Suspend or reinstate an account.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $action = $request->validated('action');

        $this->refuseSelfTargeting($actor, $user);
        $this->refuseProtectedTargets($actor, $user);

        $before = ['status' => $user->status->value];

        if ($action === 'suspend') {
            $user->forceFill([
                'status' => UserStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $request->validated('reason'),
            ])->save();

            $this->audit->recordChange(
                AdminAction::UserSuspended,
                $user,
                $before,
                ['status' => $user->status->value, 'reason' => $request->validated('reason')],
                "Suspended {$user->name}",
            );
        } else {
            $user->forceFill([
                'status' => UserStatus::Active,
                // Cleared on reinstatement: a suspension that has been lifted
                // should not keep reporting why it once happened, or the next
                // reader will think the account is still under it.
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            $this->audit->recordChange(
                AdminAction::UserActivated,
                $user,
                $before,
                ['status' => $user->status->value],
                "Reinstated {$user->name}",
            );
        }

        return response()->json(['user' => UserResource::make($user->refresh()->load('roles:id,name'))]);
    }

    /**
     * An administrator cannot suspend their own account.
     *
     * Not paternalism: it is the one action that can lock the platform's own
     * operator out of the screen they would use to undo it.
     */
    private function refuseSelfTargeting(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'action' => 'You cannot change the status of your own account.',
            ]);
        }
    }

    /**
     * Only a super administrator may act against another administrator.
     *
     * Without this, an ordinary administrator could suspend the super
     * administrator and take the platform — the roles, the permissions, the
     * settings — with them.
     */
    private function refuseProtectedTargets(User $actor, User $target): void
    {
        if (! $target->isAdmin()) {
            return;
        }

        if (! $actor->hasRole(UserRole::SuperAdmin->value)) {
            throw ValidationException::withMessages([
                'action' => 'Only a super administrator may change an administrator account.',
            ]);
        }

        /*
         * A super administrator is refused here even for another super
         * administrator. Snowflake accounts are the last way back into a
         * platform whose other administrative access has gone wrong, and the
         * place to remove one is the database, deliberately, not a button.
         */
        if ($target->hasRole(UserRole::SuperAdmin->value)) {
            throw ValidationException::withMessages([
                'action' => 'A super administrator account cannot be suspended from the console.',
            ]);
        }
    }
}
