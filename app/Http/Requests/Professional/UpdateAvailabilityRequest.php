<?php

namespace App\Http\Requests\Professional;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the whole weekly pattern in one call.
 *
 * The editor shows all seven days at once, so a per-day endpoint would make a
 * save a sequence of requests that can half-apply — clear Sunday, then fail
 * saving Monday, and the professional's week is wrong in a way they cannot see.
 */
class UpdateAvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['present', 'array', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            'days.*.end_time' => ['required', 'date_format:H:i,H:i:s'],
        ];
    }

    /**
     * Compare each day's end against its own start.
     *
     * A wildcard `after:days.*.start_time` would compare every end time against
     * every start time, so the ordered check is done explicitly here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('days', []) as $index => $day) {
                $start = $day['start_time'] ?? null;
                $end = $day['end_time'] ?? null;

                if (! is_string($start) || ! is_string($end)) {
                    continue;
                }

                if (strtotime($end) <= strtotime($start)) {
                    $validator->errors()->add(
                        "days.{$index}.end_time",
                        'The finish time must be after the start time.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days.*.day_of_week.distinct' => 'Each day of the week can only appear once.',
            'days.*.start_time.date_format' => 'Enter times as HH:MM.',
            'days.*.end_time.date_format' => 'Enter times as HH:MM.',
        ];
    }
}
