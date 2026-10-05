<?php

namespace App\Policies;

use App\Models\PortfolioItem;
use App\Models\User;

class PortfolioItemPolicy
{
    public function view(User $user, PortfolioItem $item): bool
    {
        // A draft is the owner's business until it is published.
        return $item->is_published
            || $user->id === $item->professionalProfile?->user_id
            || $user->isAdmin();
    }

    public function manage(User $user, PortfolioItem $item): bool
    {
        return $user->id === $item->professionalProfile?->user_id;
    }

    public function create(User $user, PortfolioItem $item): bool
    {
        return $this->manage($user, $item);
    }

    public function update(User $user, PortfolioItem $item): bool
    {
        return $this->manage($user, $item);
    }

    public function delete(User $user, PortfolioItem $item): bool
    {
        return $this->manage($user, $item);
    }
}
