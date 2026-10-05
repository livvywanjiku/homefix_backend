<?php

namespace App\Http\Controllers\Api\Professional;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalProfileResource;
use App\Models\ProfessionalProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Asking to be reviewed (spec §19).
 *
 * Kept separate from filing documents because it is the one place a profile's
 * verification state changes from the professional's side. Everything the
 * platform needs before a human looks at the application is checked here, so
 * the review queue only ever contains applications that are ready for it.
 */
class VerificationController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function submit(Request $request): ProfessionalProfileResource
    {
        $profile = $this->profile($request->user());

        if (! $profile->verification_status->isResubmittable()) {
            throw ValidationException::withMessages([
                'verification' => $profile->isVerified()
                    ? 'Your profile is already verified.'
                    : 'Your account is suspended. Please contact support.',
            ]);
        }

        $missing = $this->missingRequirements($profile);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'verification' => 'Before we can review your application, add '.$this->listForHumans($missing).'.',
            ]);
        }

        // Re-entering the queue clears the previous rejection: the notes
        // described what was wrong last time, and leaving them on a pending
        // application would show a reviewer a stale reason.
        $profile->forceFill([
            'verification_status' => VerificationStatus::Pending,
            'verification_notes' => null,
        ])->save();

        return ProfessionalProfileResource::make($this->loadProfileForEditor($profile));
    }

    /**
     * What an application still needs before it is worth reviewing.
     *
     * @return array<int, string>
     */
    private function missingRequirements(ProfessionalProfile $profile): array
    {
        $missing = [];

        // A profile offering nothing cannot be given work, so verification
        // would achieve nothing for either side.
        if (! $profile->services()->where('is_active', true)->exists()) {
            $missing[] = 'at least one active service';
        }

        foreach (DocumentType::cases() as $type) {
            $hasDocument = $profile->documents()->where('type', $type->value)->exists();

            if ($type->isRequired() && ! $hasDocument) {
                $missing[] = 'a '.strtolower($type->label());
            }
        }

        return $missing;
    }

    /**
     * Join requirements into something a person would say out loud, rather
     * than a comma-spliced list.
     *
     * @param  array<int, string>  $items
     */
    private function listForHumans(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
