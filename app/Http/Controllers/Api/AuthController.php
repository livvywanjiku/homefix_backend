<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * The message returned by every password-reset request, whatever the
     * outcome. Distinguishing "sent" from "no such address" would turn the
     * endpoint into an account-enumeration oracle.
     */
    private const RESET_LINK_MESSAGE = 'If that email address is registered, a reset link has been sent.';

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // The user and their role must land together: a user row without a
        // role would be unable to do anything and awkward to repair.
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            $user->assignRole($data['role']);

            return $user;
        });

        return $this->tokenResponse($user, $request->deviceName(), Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Checked only after the password proves correct, so that account
        // status is never revealed to someone guessing.
        if (! $user->isActive()) {
            abort(Response::HTTP_FORBIDDEN, 'This account has been suspended.');
        }

        $request->clearRateLimiter();

        return $this->tokenResponse($user, $request->deviceName());
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        // Guarded because a session-authenticated request would hand back a
        // TransientToken, which has no delete().
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        // Logged rather than returned: a delivery failure must be visible to
        // operators without becoming an enumeration signal for callers.
        if ($status !== Password::RESET_LINK_SENT) {
            Log::info('Password reset link not sent.', [
                'status' => $status,
                'email' => $request->validated('email'),
            ]);
        }

        return response()->json(['message' => self::RESET_LINK_MESSAGE]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                // A reset must end every existing session: otherwise a stolen
                // token would outlive the very act meant to revoke it.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => match ($status) {
                    Password::INVALID_TOKEN => 'This password reset link is invalid or has expired.',
                    Password::INVALID_USER => 'We cannot find an account with that email address.',
                    default => 'The password could not be reset. Please request a new link.',
                },
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. Please sign in.']);
    }

    /**
     * Issues a personal access token and describes it to the caller.
     *
     * `expires_at` is computed here because Sanctum stores no expiry on the
     * token itself — it enforces `created_at + sanctum.expiration` at
     * verification time. Without reporting it, the BFF would have to duplicate
     * the backend's lifetime to size its cookie.
     */
    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));

        $token = $user->createToken($deviceName);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => UserResource::make($user)->resolve(),
        ], $status);
    }
}
