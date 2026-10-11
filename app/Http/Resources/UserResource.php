<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_path,
            'status' => $this->status->value,
            'role' => $this->primaryRole()?->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        $viewer = $request->user();

        /*
         * An administrator's own grant list, and only their own.
         *
         * The administration sidebar is built from permissions rather than from
         * a role name, so the signed-in administrator's screen has to know what
         * it may do. Reading it live from the account rather than baking it into
         * the session cookie means a permission revoked in the database takes
         * effect on the next request rather than at the next sign-in.
         *
         * Restricted to the viewer's own row on purpose. A paginated list of
         * fifty users would otherwise load fifty role sets and fifty permission
         * sets to render one sidebar, and no screen needs to know what grants
         * somebody else holds — only which roles they have, which the resource
         * already reports above.
         */
        if ($viewer instanceof User && $viewer->isAdmin() && $viewer->is($this->resource)) {
            $data['roles'] = $this->roles->pluck('name')->values()->all();
            $data['permissions'] = $this->getAllPermissions()->pluck('name')->values()->all();
        }

        return $data;
    }
}
