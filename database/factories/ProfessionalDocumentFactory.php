<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\ProfessionalDocument;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalDocument>
 */
class ProfessionalDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'professional_profile_id' => ProfessionalProfile::factory(),
            'type' => DocumentType::NationalId,

            // A path on the `public` disk. Tests that exercise the upload
            // itself use Storage::fake with a real UploadedFile.
            'path' => 'documents/'.fake()->uuid().'.pdf',
            'status' => VerificationStatus::Pending,
            'notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function ofType(DocumentType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationStatus::Verified,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'The document was illegible.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationStatus::Rejected,
            'notes' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    public function forProfile(ProfessionalProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_profile_id' => $profile->id,
        ]);
    }
}
