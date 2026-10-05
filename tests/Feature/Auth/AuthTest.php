<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles must exist before any user can be given one.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amina Wanjiru',
            'email' => 'amina@example.test',
            'phone' => '+254712345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Customer->value,
        ], $overrides);
    }

    public function test_a_customer_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('user.role', UserRole::Customer->value)
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'role', 'status']]);

        $this->assertDatabaseHas('users', ['email' => 'amina@example.test', 'status' => UserStatus::Active->value]);
        $this->assertTrue(User::where('email', 'amina@example.test')->firstOrFail()->hasRole(UserRole::Customer->value));
    }

    public function test_a_professional_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'pro@example.test',
            'phone' => '+254798765432',
            'role' => UserRole::Professional->value,
        ]));

        $response->assertCreated()->assertJsonPath('user.role', UserRole::Professional->value);
    }

    /**
     * The escalation guard: public registration must never mint an admin.
     */
    public function test_registration_cannot_create_an_admin(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'role' => UserRole::Admin->value,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'amina@example.test']);
        $this->assertSame(0, User::role(UserRole::Admin->value)->count());
    }

    public function test_registration_requires_a_unique_email(): void
    {
        User::factory()->customer()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => 'taken@example.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_a_unique_phone(): void
    {
        User::factory()->customer()->create(['phone' => '+254700000000']);

        $this->postJson('/api/auth/register', $this->registrationPayload(['phone' => '+254700000000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload([
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_never_leaks_the_password_hash(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload());

        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $response->assertJsonMissingPath('user.password');
    }

    public function test_login_returns_a_token_and_the_effective_expiry(): void
    {
        User::factory()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', UserRole::Customer->value)
            ->assertJsonStructure(['token', 'expires_at']);

        $this->assertNotNull($response->json('token'));

        // The reported expiry must match the configured token lifetime, since
        // the BFF sizes its cookie from this value.
        $expected = now()->addMinutes((int) config('sanctum.expiration'));
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            strtotime((string) $response->json('expires_at')),
            5
        );
    }

    public function test_login_rejects_the_wrong_password(): void
    {
        User::factory()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'not-the-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_an_unknown_email_without_revealing_it(): void
    {
        $known = User::factory()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'not-the-password',
        ]);

        $unknownUser = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-password',
        ]);

        $this->assertSame($wrongPassword->json('errors.email'), $unknownUser->json('errors.email'));
        $this->assertSame($known->email, 'amina@example.test');
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'amina@example.test',
                'password' => 'not-the-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'not-the-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        // Even the correct password is refused while the window is open.
        $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'password123',
        ])->assertUnprocessable();

        $this->assertStringContainsString(
            'Too many login attempts',
            (string) $this->postJson('/api/auth/login', [
                'email' => 'amina@example.test',
                'password' => 'not-the-password',
            ])->json('errors.email.0')
        );
    }

    public function test_a_successful_login_clears_the_throttle(): void
    {
        User::factory()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'amina@example.test',
                'password' => 'not-the-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'password123',
        ])->assertOk();

        // Budget restored: another five failures are allowed before lockout.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'amina@example.test',
                'password' => 'not-the-password',
            ])->assertJsonMissing(['errors' => ['email' => ['Too many login attempts. Please try again in 60 seconds.']]]);
        }
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        User::factory()->suspended()->customer()->create([
            'email' => 'amina@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'amina@example.test',
            'password' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Suspension has to invalidate tokens that were issued before it, not just
     * block future sign-ins.
     */
    public function test_a_suspended_users_existing_token_is_refused(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('test')->plainTextToken;

        // The token works while the account is active.
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $user->forceFill([
            'status' => UserStatus::Suspended,
            'suspended_at' => now(),
        ])->save();

        $this->forgetResolvedGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->customer()->create();
        $first = $user->createToken('first')->plainTextToken;
        $second = $user->createToken('second')->plainTextToken;

        $this->withToken($first)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->forgetResolvedGuards();

        $this->withToken($first)->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * A client that does not ask for JSON must get a 401, not a 500.
     *
     * `getJson()` always sends `Accept: application/json`, which short-circuits
     * the guest redirect — so the rest of the suite cannot catch a failure
     * here. This uses a plain request, matching a bare `curl`.
     */
    public function test_an_unauthenticated_request_without_a_json_accept_header_is_not_an_error(): void
    {
        $this->get('/api/auth/me', ['Accept' => '*/*'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->professional()->create(['name' => 'Brian Otieno']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Brian Otieno')
            ->assertJsonPath('data.role', UserRole::Professional->value)
            ->assertJsonMissingPath('data.password');
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->customer()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.test']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->customer()->create(['email' => 'amina@example.test']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'amina@example.test',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
    }

    /**
     * Resetting a password must end the sessions a thief may be holding.
     */
    public function test_a_password_reset_revokes_existing_tokens(): void
    {
        $user = User::factory()->customer()->create(['email' => 'amina@example.test']);
        $stolen = $user->createToken('stolen')->plainTextToken;

        $this->withToken($stolen)->getJson('/api/auth/me')->assertOk();

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'amina@example.test',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->forgetResolvedGuards();

        $this->withToken($stolen)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_password_reset_rejects_an_invalid_token(): void
    {
        User::factory()->customer()->create(['email' => 'amina@example.test']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'amina@example.test',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    /**
     * Forces the next request to re-read the bearer token from scratch.
     *
     * The test harness reuses one application container for the whole test,
     * and `AuthManager` keeps its guard instances in that container.
     * `RequestGuard::user()` then caches the first user it resolves and
     * returns it on every later call — so a request that should observe a
     * revoked token or a suspended account is answered from the previous
     * request's cache and appears to succeed.
     *
     * A null result is *not* cached, which is why only the "was signed in,
     * should no longer be" assertions are affected.
     *
     * This is purely a harness artifact: in production the container is
     * rebuilt per request, and the platform runs no persistent worker
     * (no Octane/Swoole/RoadRunner), so a guard never outlives its request.
     * Drop this call the day the suite stops sharing one container.
     */
    private function forgetResolvedGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
