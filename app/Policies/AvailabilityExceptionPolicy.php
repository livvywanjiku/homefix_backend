<?php

namespace App\Policies;

use App\Models\AvailabilityException;
use App\Models\User;

class AvailabilityExceptionPolicy
{
    /**
     * A blocked date belongs to the professional who set it.
     */
    public function delete(User $user, AvailabilityException $exception): bool
    {
        return $user->id === $exception->professionalProfile?->user_id;
    }
}
