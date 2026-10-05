<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Applied to the whole API surface rather than to each authenticated
        // group: the middleware is a no-op for guests, and appending it here
        // means a new route cannot be added without the suspension check.
        $middleware->appendToGroup('api', EnsureUserIsActive::class);

        // This application has no web login route — the Next.js frontend owns
        // sign-in. Laravel's default guest redirect calls route('login') while
        // *constructing* the AuthenticationException, so for any request that
        // does not send `Accept: application/json` the RouteNotFoundException
        // fires first and the client sees a 500 instead of a 401. Returning
        // null lets the exception be thrown, and the handler below then
        // renders it as JSON for api/*.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
