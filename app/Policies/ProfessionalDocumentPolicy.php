<?php

namespace App\Policies;

use App\Models\ProfessionalDocument;
use App\Models\User;

class ProfessionalDocumentPolicy
{
    /**
     * A verification document is sensitive: it is an identity or licence. Only
     * the professional who filed it and an administrator reviewing it may read
     * it — no customer, and no other professional.
     */
    public function view(User $user, ProfessionalDocument $document): bool
    {
        return $user->isAdmin()
            || $user->id === $document->professionalProfile?->user_id;
    }

    /**
     * A professional files and withdraws their own documents. Withdrawing is
     * allowed only before review, so a rejected document stays on the record
     * with its reviewer's note.
     */
    public function delete(User $user, ProfessionalDocument $document): bool
    {
        return $user->id === $document->professionalProfile?->user_id
            && $document->status->isResubmittable();
    }

    /**
     * Approving or rejecting is an administrator's decision. The route also
     * requires the `verify_professional` permission.
     */
    public function review(User $user, ProfessionalDocument $document): bool
    {
        return $user->isAdmin();
    }
}
