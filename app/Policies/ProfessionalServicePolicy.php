<?php

namespace App\Policies;

use App\Models\ProfessionalService;
use App\Models\User;

class ProfessionalServicePolicy
{
    /**
     * The owner manages their own offerings. Ownership is held by the parent
     * profile, not the offering, so the check is one hop up.
     */
    public function manage(User $user, ProfessionalService $offering): bool
    {
        return $user->id === $offering->professionalProfile?->user_id;
    }

    public function create(User $user, ProfessionalService $offering): bool
    {
        return $this->manage($user, $offering);
    }

    public function update(User $user, ProfessionalService $offering): bool
    {
        return $this->manage($user, $offering);
    }

    public function delete(User $user, ProfessionalService $offering): bool
    {
        return $this->manage($user, $offering);
    }
}
