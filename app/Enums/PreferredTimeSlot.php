<?php

namespace App\Enums;

/**
 * The part of the day the customer would prefer (spec §8).
 *
 * A window rather than a clock time, because a customer cannot commit a
 * tradesperson to an exact hour days in advance. The precise appointment is
 * agreed later, when a quotation is accepted and a booking is scheduled.
 */
enum PreferredTimeSlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morning (8am – 12pm)',
            self::Afternoon => 'Afternoon (12pm – 5pm)',
            self::Evening => 'Evening (5pm – 8pm)',
        };
    }

    /**
     * Every slot, for validation rules and pickers.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $slot): string => $slot->value, self::cases());
    }
}
