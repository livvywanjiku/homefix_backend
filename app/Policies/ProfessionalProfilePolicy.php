<?php

namespace App\Policies;

use App\Models\ProfessionalProfile;
use App\Models\User;

/**
 * Ownership rules for a professional's own profile.
 *
 * Capability gates (`permission:verify_professional`) live on the routes; these
 * methods answer the separate question of whether *this* user may act on *this*
 * record.
 */
class ProfessionalProfilePolicy
{
    /**
     * Anyone may view a verified professional. An unverified profile is
     * visible only to its owner and to administrators, so an applicant can see
     * their own page while it is still in review without it being public.
     */
    public function view(User $user, ProfessionalProfile $profile): bool
    {
        return $profile->isVerified()
            || $user->id === $profile->user_id
            || $user->isAdmin();
    }

    /**
     * Editing is the owner's alone — an administrator reviews an application
     * through the verification endpoints rather than rewriting it.
     */
    public function update(User $user, ProfessionalProfile $profile): bool
    {
        return $user->id === $profile->user_id;
    }

    /**
     * Submitting for review, and managing services, availability, portfolio and
     * documents, are all owner-only actions on the same profile.
     */
    public function manage(User $user, ProfessionalProfile $profile): bool
    {
        return $user->id === $profile->user_id;
    }

    /**
     * A customer may save or unsave a professional; a professional may not
     * favourite themselves.
     */
    public function favorite(User $user, ProfessionalProfile $profile): bool
    {
        return $profile->isVerified() && $user->id !== $profile->user_id;
    }
}
