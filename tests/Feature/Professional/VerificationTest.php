<?php

namespace Tests\Feature\Professional;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\ProfessionalDocument;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verification: the professional asks to be reviewed, an administrator decides.
 *
 * This is the platform's trust boundary, so the tests cover both sides of it —
 * what the professional may push, and what an administrator may change.
 */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A professional with everything an application needs: one active service
     * and an identity document on file.
     */
    private function readyProfile(): ProfessionalProfile
    {
        $profile = ProfessionalProfile::factory()
            ->forUser(User::factory()->professional()->create())
            ->create();

        ProfessionalService::factory()->forProfile($profile)->fixed(150000)->create();
        ProfessionalDocument::factory()->forProfile($profile)->create([
            'type' => DocumentType::NationalId,
        ]);

        return $profile;
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    public function test_a_bare_profile_cannot_be_submitted_for_review(): void
    {
        $profile = ProfessionalProfile::factory()
            ->forUser(User::factory()->professional()->create())
            ->create();

        // Nothing to review: no service offered and no identity on file.
        $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/verification/submit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification');

        $this->assertSame(VerificationStatus::Pending, $profile->fresh()->verification_status);
    }

    public function test_a_professional_can_submit_once_the_application_is_complete(): void
    {
        $profile = $this->readyProfile();

        $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/verification/submit')
            ->assertOk()
            ->assertJsonPath('data.verification_status', VerificationStatus::Pending->value);
    }

    /**
     * A verified professional resubmitting would drop them out of search for no
     * reason, so the transition is refused rather than silently ignored.
     */
    public function test_a_verified_professional_cannot_submit_again(): void
    {
        $profile = $this->readyProfile();
        $profile->forceFill([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ])->save();

        $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/verification/submit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification');

        $this->assertTrue($profile->fresh()->isVerified());
    }

    public function test_resubmitting_clears_the_previous_rejection_reason(): void
    {
        $profile = $this->readyProfile();
        $profile->forceFill([
            'verification_status' => VerificationStatus::Rejected,
            'verification_notes' => 'The ID photo was too blurry to read.',
        ])->save();

        $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/verification/submit')
            ->assertOk();

        $profile->refresh();

        $this->assertSame(VerificationStatus::Pending, $profile->verification_status);
        $this->assertNull($profile->verification_notes);
    }

    /*
    |--------------------------------------------------------------------------
    | The admin queue
    |--------------------------------------------------------------------------
    */

    public function test_the_verification_queue_requires_an_administrator(): void
    {
        $this->getJson('/api/admin/professionals')->assertUnauthorized();

        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/admin/professionals')
            ->assertForbidden();

        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->getJson('/api/admin/professionals')
            ->assertForbidden();
    }

    public function test_the_queue_defaults_to_the_applications_still_needing_work(): void
    {
        ProfessionalProfile::factory()->create();
        ProfessionalProfile::factory()->rejected()->create();
        ProfessionalProfile::factory()->verified()->create();

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/admin/professionals');

        // Pending and rejected are the ones with work left; a verified profile
        // is not the queue's business.
        $response->assertOk()->assertJsonCount(2, 'data');

        $statuses = array_column($response->json('data'), 'verification_status');
        $this->assertNotContains(VerificationStatus::Verified->value, $statuses);
    }

    public function test_the_queue_can_be_filtered_to_one_status(): void
    {
        ProfessionalProfile::factory()->create();
        ProfessionalProfile::factory()->verified()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/admin/professionals?status='.VerificationStatus::Verified->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verification_status', VerificationStatus::Verified->value);
    }

    /**
     * The queue is where an administrator decides, so it is the one place the
     * applicant's contact details appear.
     */
    public function test_the_queue_shows_the_applicants_contact_details(): void
    {
        ProfessionalProfile::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/admin/professionals')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'business_name', 'user' => ['id', 'name', 'email']]]]);
    }

    /*
    |--------------------------------------------------------------------------
    | Decisions
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_verify_a_professional(): void
    {
        $profile = ProfessionalProfile::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", [
                'decision' => 'verify',
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', VerificationStatus::Verified->value)
            ->assertJsonPath('data.is_verified', true);

        $profile->refresh();

        $this->assertTrue($profile->isVerified());
        $this->assertNotNull($profile->verified_at);
    }

    /**
     * A rejection with no reason leaves the professional nothing to fix.
     */
    public function test_a_rejection_must_carry_a_reason(): void
    {
        $profile = ProfessionalProfile::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", [
                'decision' => 'reject',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    public function test_a_rejected_profile_carries_no_verification_timestamp(): void
    {
        // Verified first, so the assertion proves the timestamp is cleared
        // rather than merely never set.
        $profile = ProfessionalProfile::factory()->verified()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", [
                'decision' => 'reject',
                'notes' => 'The trade certificate does not match the business name.',
            ])
            ->assertOk();

        $profile->refresh();

        $this->assertSame(VerificationStatus::Rejected, $profile->verification_status);
        $this->assertNull($profile->verified_at);
    }

    public function test_an_administrator_can_suspend_a_verified_professional(): void
    {
        $profile = ProfessionalProfile::factory()->verified()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", [
                'decision' => 'suspend',
                'notes' => 'Two unresolved complaints.',
            ])
            ->assertOk();

        $profile->refresh();

        $this->assertSame(VerificationStatus::Suspended, $profile->verification_status);

        // The suspension records that they *were* verified; erasing that would
        // lose the history support needs.
        $this->assertNotNull($profile->verified_at);
    }

    public function test_an_unknown_decision_is_rejected(): void
    {
        $profile = ProfessionalProfile::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", [
                'decision' => 'approve_everything',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('decision');
    }

    public function test_a_customer_cannot_decide_on_an_application(): void
    {
        $profile = ProfessionalProfile::factory()->create();

        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->postJson("/api/admin/professionals/{$profile->id}/review", ['decision' => 'verify'])
            ->assertForbidden();

        $this->assertFalse($profile->fresh()->isVerified());
    }

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_reject_a_document_with_a_reason(): void
    {
        $document = ProfessionalDocument::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/documents/{$document->id}/review", [
                'decision' => 'reject',
                'notes' => 'The expiry date is not visible.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', VerificationStatus::Rejected->value)

            // The reason is the point of the endpoint: it is what the
            // professional reads before uploading a replacement.
            ->assertJsonPath('data.notes', 'The expiry date is not visible.');

        $document->refresh();

        $this->assertNotNull($document->reviewed_at);
        $this->assertNotNull($document->reviewed_by);
    }

    public function test_an_administrator_can_approve_a_document(): void
    {
        $document = ProfessionalDocument::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/documents/{$document->id}/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', VerificationStatus::Verified->value);
    }

    public function test_a_document_rejection_must_carry_a_reason(): void
    {
        $document = ProfessionalDocument::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/admin/documents/{$document->id}/review", ['decision' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    public function test_a_professional_cannot_review_their_own_document(): void
    {
        $document = ProfessionalDocument::factory()->create();

        $this->actingAs($document->professionalProfile->user, 'sanctum')
            ->postJson("/api/admin/documents/{$document->id}/review", ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame(VerificationStatus::Pending, $document->fresh()->status);
    }
}
