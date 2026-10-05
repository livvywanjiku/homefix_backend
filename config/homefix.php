<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrator Bootstrap
    |--------------------------------------------------------------------------
    |
    | Credentials for the seeded platform administrator. Public registration
    | can only create customers and professionals, so this account is the only
    | route into the admin console.
    |
    | There is no fallback password: seeding warns and skips when the password
    | is unset, because a weak default admin is worse than none.
    |
    */

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@homefix.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Where password-reset links send the user. The frontend owns the reset
    | form, so Laravel must not build a link to its own `password.reset` route,
    | which this application does not register.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

];
