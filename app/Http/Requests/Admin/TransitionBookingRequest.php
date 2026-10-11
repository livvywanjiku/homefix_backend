<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator moving a booking to another state (spec §20).
 *
 * The rule only says "this is a status the platform has". Whether the move is
 * legal from where the booking currently stands is decided by the state machine
 * in {@see \App\Services\Bookings\BookingService}, which is the same guard the
 * customer and professional flows go through — an administrator arbitrating a
 * dispute is not exempt from the rules that define the dispute.
 */
class TransitionBookingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BookingStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function target(): BookingStatus
    {
        return BookingStatus::from($this->validated('status'));
    }
}
