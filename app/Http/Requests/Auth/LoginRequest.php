<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\DeterminesDeviceName;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates credentials and throttles guessing.
 *
 * Credential verification deliberately lives in the controller rather than a
 * `authenticate()` method here: the platform issues tokens, and calling
 * `Auth::attempt()` would additionally start a session on the web guard.
 */
class LoginRequest extends FormRequest
{
    use DeterminesDeviceName;

    /** Attempts allowed per email + IP pair before the window closes. */
    private const MAX_ATTEMPTS = 5;

    /** Length of the throttle window, in seconds. */
    private const DECAY_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Reject the attempt when the email + IP pair has exhausted its budget.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    /** Counts a failed attempt against the caller. */
    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    /** Forgets past failures once the credentials prove correct. */
    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Keyed on the email *and* the IP, so one attacker cannot lock out an
     * account they do not own, and one IP cannot spray many accounts.
     */
    private function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower((string) $this->string('email')).'|'.$this->ip()
        );
    }
}
