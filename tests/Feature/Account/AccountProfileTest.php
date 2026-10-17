<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The signed-in account's own profile: name, phone and avatar.
 *
 * The negative cases matter most here: these endpoints are reachable by both
 * roles and always act on the caller's own row, so the risks are an
 * unauthorised write and a leaked file, not a wrong price.
 */
class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    /**
     * A real one-pixel PNG. GD is unavailable on this machine, so
     * `fake()->image()` cannot generate one; genuine bytes also exercise the
     * `image` rule rather than only a filename.
     */
    private function fakeImage(string $name = 'avatar.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );
    }

    private function customer(): User
    {
        return User::factory()->customer()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Avatars
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_can_upload_an_avatar(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/auth/avatar', ['avatar' => $this->fakeImage()])
            ->assertOk();

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("avatars/{$user->id}/", $path);
        Storage::disk('public')->assertExists($path);

        $this->assertSame(url('storage/'.$path), $response->json('data.avatar_url'));
    }

    public function test_a_professional_can_upload_an_avatar(): void
    {
        $user = User::factory()->professional()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/auth/avatar', ['avatar' => $this->fakeImage()])
            ->assertOk();

        Storage::disk('public')->assertExists($user->refresh()->avatar_path);
    }

    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->post('/api/auth/avatar', ['avatar' => $this->fakeImage()])
            ->assertOk();

        $first = $user->refresh()->avatar_path;

        $this->actingAs($user, 'sanctum')
            ->post('/api/auth/avatar', ['avatar' => $this->fakeImage('replacement.png')])
            ->assertOk();

        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_an_avatar_must_be_an_image(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->post('/api/auth/avatar', [
                'avatar' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_avatar_over_four_megabytes_is_rejected(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->post('/api/auth/avatar', [
                'avatar' => UploadedFile::fake()->create('large.png', 4097, 'image/png'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_a_customer_can_remove_their_avatar(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->post('/api/auth/avatar', ['avatar' => $this->fakeImage()])
            ->assertOk();

        $path = $user->refresh()->avatar_path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/avatar')
            ->assertNoContent();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Name and phone
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_can_update_their_name_and_phone(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'name' => 'Achieng Otieno',
                'phone' => '+254712345678',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Achieng Otieno')
            ->assertJsonPath('data.phone', '+254712345678');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Achieng Otieno',
            'phone' => '+254712345678',
        ]);
    }

    public function test_a_professional_can_update_their_account_details(): void
    {
        $user = User::factory()->professional()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'name' => 'Kiptoo Mwangi',
                'phone' => '+254733000111',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'phone' => '+254733000111']);
    }

    public function test_a_phone_number_belongs_to_one_account(): void
    {
        $other = $this->customer();
        $other->update(['phone' => '+254700111222']);

        $this->actingAs($this->customer(), 'sanctum')
            ->putJson('/api/auth/profile', [
                'name' => 'Someone Else',
                'phone' => '+254700111222',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_saving_your_own_phone_number_is_not_a_duplicate(): void
    {
        $user = $this->customer();
        $user->update(['phone' => '+254711222333']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/auth/profile', [
                'name' => $user->name,
                'phone' => '+254711222333',
            ])
            ->assertOk();
    }

    public function test_a_phone_number_is_required_when_saving_the_profile(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->putJson('/api/auth/profile', [
                'name' => 'No Phone',
                'phone' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_the_account_endpoints_require_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->putJson('/api/auth/profile', [])->assertUnauthorized();
        $this->post('/api/auth/avatar', [])->assertUnauthorized();
        $this->deleteJson('/api/auth/avatar')->assertUnauthorized();
    }
}
