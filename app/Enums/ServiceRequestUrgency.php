<?php

namespace App\Enums;

/**
 * How soon the customer needs the work done (spec §8).
 *
 * A request board sorted purely by posting date buries the jobs that actually
 * need answering today. Urgency is what lets a professional see at a glance
 * which listing to read first.
 */
enum ServiceRequestUrgency: string
{
    case Flexible = 'flexible';
    case Soon = 'soon';
    case Urgent = 'urgent';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Flexible => 'Flexible — no rush',
            self::Soon => 'Within a few days',
            self::Urgent => 'Urgent — today if possible',
        };
    }

    /**
     * Sort weight, so "urgent" lists above "flexible" within any date ordering.
     *
     * Declared here rather than as a SQL CASE so the ordering cannot drift from
     * the labels above.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 3,
            self::Soon => 2,
            self::Flexible => 1,
        };
    }

    /**
     * Every urgency, for validation rules and filter dropdowns.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $urgency): string => $urgency->value, self::cases());
    }
}
