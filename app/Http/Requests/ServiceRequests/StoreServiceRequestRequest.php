<?php

namespace App\Http\Requests\ServiceRequests;

use App\Enums\PreferredTimeSlot;
use App\Enums\ServiceRequestUrgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Posting a job (spec §8).
 *
 * Like the rest of the API, authorisation lives in the route middleware and the
 * policy rather than here — see `ServiceRequestPolicy::create`.
 */
class StoreServiceRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Required even though the column is nullable. The nullability
             * exists so a request survives its service being retired from the
             * catalogue, not so one can be posted against nothing — a job with
             * no service cannot be matched to a professional.
             */
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('is_active', true),
            ],

            // Required for the same reason: matching runs on the town.
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],

            'title' => ['required', 'string', 'max:150'],

            // A floor as well as a ceiling: "fix it" is not a brief anyone can
            // quote, and a minimum length is the cheapest way to ask for detail.
            'description' => ['required', 'string', 'min:20', 'max:5000'],

            'instructions' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],

            /*
             * A pin is optional, but a half-supplied one is not: a latitude with
             * no longitude cannot be placed on a map, and storing it would put
             * the job in the Gulf of Guinea.
             */
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],

            // A date in the past is a typo, not a preference.
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_time_slot' => ['nullable', Rule::enum(PreferredTimeSlot::class)],

            'budget_min_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'budget_max_cents' => [
                'nullable',
                'integer',
                'min:0',
                'max:100000000',
                // Only meaningful when both ends are given; a max alone is fine.
                'gte:budget_min_cents',
            ],

            'urgency' => ['required', Rule::enum(ServiceRequestUrgency::class)],

            /*
             * Photos may travel with the create call so the wizard can post in
             * one step. 4MB and five images is a deliberate ceiling: a request
             * is a description with supporting evidence, not a photo album.
             */
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.exists' => 'That service is not available.',
            'location_id.exists' => 'That location is not available.',
            'description.min' => 'Describe the job in at least 20 characters so a professional can quote it.',
            'latitude.required_with' => 'Drop the pin on the map to set both coordinates.',
            'longitude.required_with' => 'Drop the pin on the map to set both coordinates.',
            'budget_max_cents.gte' => 'The upper budget must be at least the lower one.',
            'preferred_date.after_or_equal' => 'Choose a date today or later.',
            'images.max' => 'Attach at most 5 photos.',
            'images.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'images.*.max' => 'Each photo must be 4 MB or smaller.',
        ];
    }
}
