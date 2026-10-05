<?php

namespace App\Http\Requests\Auth\Concerns;

use Illuminate\Support\Str;

/**
 * Names the session token, so an account's active sessions are identifiable.
 *
 * Shared by the sign-in and sign-up requests because both issue a token.
 */
trait DeterminesDeviceName
{
    /**
     * The client-supplied device label, falling back to the User-Agent.
     */
    public function deviceName(): string
    {
        return $this->validated('device_name')
            ?? Str::limit((string) $this->userAgent(), 255, '');
    }
}
