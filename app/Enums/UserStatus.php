<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
