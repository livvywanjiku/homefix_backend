<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The single place the platform's password policy is defined. Form
        // requests reference Password::defaults() so the rule can be tightened
        // here without hunting down every validator.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // The reset form lives in the Next.js app, so the emailed link has to
        // point there. Without this, Laravel builds a URL for its own
        // `password.reset` route, which this API-only application does not
        // register — the notification would fail at send time.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim((string) config('homefix.frontend_url'), '/')
                ."/reset-password/{$token}?email={$email}";
        });
    }
}
