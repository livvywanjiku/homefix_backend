<?php

namespace App\Http\Controllers\Api\Professional\Concerns;

use App\Models\ProfessionalProfile;
use App\Models\User;

/**
 * Shared access to the signed-in professional's own profile.
 *
 * Every endpoint in this namespace acts on the caller's profile rather than on
 * an id from the request, so there is no route parameter to tamper with and no
 * ownership check to forget.
 */
trait InteractsWithProfessionalProfile
{
    /**
     * The given professional's profile.
     *
     * Aborts rather than returning null: every caller here either mutates the
     * profile or reports on it, and both need it to exist. The account is
     * passed in rather than read from a global so the dependency is visible at
     * each call site.
     */
    protected function profile(User $user): ProfessionalProfile
    {
        $profile = $user->professionalProfile;

        abort_if($profile === null, 404, 'Create your professional profile first.');

        return $profile;
    }

    /**
     * The profile with everything the /pro editor renders.
     *
     * Centralised because the editor, the dashboard and the verification page
     * all show overlapping slices of the same graph; loading it in one place
     * keeps them consistent and stops a new panel introducing an N+1.
     */
    protected function loadProfileForEditor(ProfessionalProfile $profile): ProfessionalProfile
    {
        return $profile->load([
            'user:id,name,avatar_path',
            'baseLocation',
            'serviceAreas',
            'services.service.category',
            'availabilities' => fn ($query) => $query->ordered(),
            'availabilityExceptions' => fn ($query) => $query->upcoming(),
            'portfolioItems' => fn ($query) => $query->ordered()->with('images'),
            'documents',
        ]);
    }
}
