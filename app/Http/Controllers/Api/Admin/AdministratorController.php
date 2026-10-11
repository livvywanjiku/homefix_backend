<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdministratorRequest;
use App\Http\Requests\Admin\UpdateAdministratorRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The administrator roster (spec §20).
 *
 * Reserved to the super administrator by the `manage_administrators` permission,
 * which only that role holds. The additional rules here are about the shape of
 * the roster rather than about the caller: nobody edits their own role, and the
 * last remaining super administrator cannot be demoted — either would leave the
 * platform with nobody able to administer it.
 */
class AdministratorController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every administrative account.
     */
    public function index(): AnonymousResourceCollection
    {
        $administrators = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', UserRole::administrativeValues()))
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(50);

        return UserResource::collection($administrators);
    }

    /**
     * Add an administrator.
     */
    public function store(StoreAdministratorRequest $request): JsonResponse
    {
        $data = $request->validated();

        $administrator = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            // Role assignment is never fillable — `User` keeps `status` and
            // everything security-relevant out of the fillable list, and roles
            // are granted explicitly here.
            $user->assignRole($data['role']);

            return $user;
        });

        $this->audit->record(
            AdminAction::AdministratorCreated,
            $administrator,
            sprintf('Created %s as %s', $administrator->email, $data['role']),
            ['role' => $data['role']],
        );

        return response()->json([
            'administrator' => UserResource::make($administrator->load('roles:id,name')),
        ], 201);
    }

    /**
     * Edit an administrator's details or role.
     */
    public function update(UpdateAdministratorRequest $request, User $administrator): JsonResponse
    {
        $this->assertIsAdministrator($administrator);

        $actor = $request->user();
        $data = $request->validated();

        /*
         * Nobody changes their own role. It is the one edit that would let an
         * administrator escalate themselves, and the one edit that could strand
         * them without the access they need to undo it.
         */
        if (isset($data['role']) && $actor->is($administrator)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role.',
            ]);
        }

        $before = [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'phone' => $administrator->phone,
            'role' => $administrator->primaryRole()?->value,
        ];

        DB::transaction(function () use ($administrator, $data): void {
            $administrator->fill(array_filter(
                $data,
                fn ($value, string $key): bool => in_array($key, ['name', 'email', 'phone'], true) && $value !== null,
                ARRAY_FILTER_USE_BOTH,
            ));

            if (filled($data['password'] ?? null)) {
                $administrator->password = $data['password'];
            }

            $administrator->save();

            if (isset($data['role'])) {
                $administrator->syncRoles([$data['role']]);
            }
        });

        $this->audit->recordChange(
            AdminAction::AdministratorUpdated,
            $administrator,
            $before,
            [
                'name' => $administrator->name,
                'email' => $administrator->email,
                'phone' => $administrator->phone,
                'role' => $administrator->primaryRole()?->value,
            ],
            "Updated {$administrator->email}",
        );

        return response()->json([
            'administrator' => UserResource::make($administrator->refresh()->load('roles:id,name')),
        ]);
    }

    /**
     * The account is administrative or the request is refused.
     *
     * Without this, the route's `{administrator}` binding would happily accept
     * any user id and the endpoint would edit a customer through a screen that
     * claims to manage administrators.
     */
    private function assertIsAdministrator(User $user): void
    {
        if (! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'administrator' => 'That account is not an administrator.',
            ]);
        }
    }
}
