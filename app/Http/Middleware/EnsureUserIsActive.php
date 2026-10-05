<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses requests from suspended accounts.
 *
 * A suspension that only blocked sign-in would leave every token already
 * issued to that account working, so the check has to sit on the request path
 * rather than at the login endpoint.
 *
 * This middleware deliberately does not revoke the tokens it rejects: it runs
 * on a read path, and the suspension action is responsible for revoking them.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authenticatedUser($request);

        if ($user && ! $user->isActive()) {
            return response()->json([
                'message' => 'This account has been suspended.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Resolves the token's user without depending on middleware order.
     *
     * This runs as API *group* middleware, which the pipeline places ahead of
     * the route's `auth:sanctum`. `$request->user()` would therefore resolve
     * the default `web` guard and always return null. Naming the guard
     * explicitly is what makes the check actually see the token.
     *
     * The bearer check matters as well: without it, every public catalogue
     * request sharing this group would do a pointless token lookup.
     */
    private function authenticatedUser(Request $request): ?User
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        return $request->user('sanctum');
    }
}
