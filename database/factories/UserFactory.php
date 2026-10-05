<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+2547########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the account has been suspended by an administrator.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Violation of platform terms.',
        ]);
    }

    /**
     * Indicate that the account holds the given role.
     */
    public function withRole(UserRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role->value);
        });
    }

    public function customer(): static
    {
        return $this->withRole(UserRole::Customer);
    }

    public function professional(): static
    {
        return $this->withRole(UserRole::Professional);
    }

    public function admin(): static
    {
        return $this->withRole(UserRole::Admin);
    }
}
