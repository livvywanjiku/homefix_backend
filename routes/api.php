<?php

use App\Http\Controllers\Api\Admin\ProfessionalVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Catalogue\ServiceCategoryController;
use App\Http\Controllers\Api\Catalogue\ServiceController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Professional\AvailabilityController;
use App\Http\Controllers\Api\Professional\DocumentController;
use App\Http\Controllers\Api\Professional\PortfolioController;
use App\Http\Controllers\Api\Professional\ProfileController as ProfessionalProfileController;
use App\Http\Controllers\Api\Professional\ServiceController as ProfessionalOfferingController;
use App\Http\Controllers\Api\Professional\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Consumed server-side by the Next.js BFF, never directly by the browser.
| Sign-in issues a Sanctum personal access token that the BFF holds in an
| httpOnly cookie.
|
*/

Route::prefix('auth')->group(function (): void {
    // Throttled per IP. Registration is included because an unthrottled
    // signup endpoint is an automated account-creation channel.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');

    // Credential throttling is keyed on email + IP inside LoginRequest; this
    // outer limit is the coarser per-IP backstop.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Catalogue
|--------------------------------------------------------------------------
|
| Unauthenticated on purpose: a visitor has to be able to browse what HomeFix
| offers, and where, before being asked to create an account. These endpoints
| expose only active records and never any customer or professional data.
|
*/

Route::get('service-categories', [ServiceCategoryController::class, 'index']);
Route::get('service-categories/{slug}', [ServiceCategoryController::class, 'show']);
Route::get('services', [ServiceController::class, 'index']);
Route::get('locations', [LocationController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Professional Self-Service
|--------------------------------------------------------------------------
|
| Everything a professional manages about their own business. No route here
| takes a professional id: the controller reads the profile from the
| authenticated account, so there is no id to tamper with and no ownership
| check that can be forgotten.
|
*/

Route::middleware(['auth:sanctum', 'role:professional'])
    ->prefix('professional')
    ->group(function (): void {
        Route::get('profile', [ProfessionalProfileController::class, 'show']);
        Route::put('profile', [ProfessionalProfileController::class, 'update']);
        Route::get('dashboard', [ProfessionalProfileController::class, 'dashboard']);

        Route::get('services', [ProfessionalOfferingController::class, 'index']);
        Route::post('services', [ProfessionalOfferingController::class, 'store']);
        Route::put('services/{offering}', [ProfessionalOfferingController::class, 'update']);
        Route::delete('services/{offering}', [ProfessionalOfferingController::class, 'destroy']);

        Route::get('availability', [AvailabilityController::class, 'index']);
        Route::put('availability', [AvailabilityController::class, 'update']);
        Route::post('availability/exceptions', [AvailabilityController::class, 'storeException']);
        Route::delete('availability/exceptions/{exception}', [AvailabilityController::class, 'destroyException']);

        Route::get('portfolio', [PortfolioController::class, 'index']);
        Route::post('portfolio', [PortfolioController::class, 'store'])->middleware('throttle:30,1');
        Route::put('portfolio/{item}', [PortfolioController::class, 'update']);
        Route::delete('portfolio/{item}', [PortfolioController::class, 'destroy']);
        Route::post('portfolio/{item}/images', [PortfolioController::class, 'uploadImages'])->middleware('throttle:30,1');
        Route::delete('portfolio/{item}/images/{image}', [PortfolioController::class, 'destroyImage']);

        // Throttled because each one writes a file to disk.
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        Route::post('verification/submit', [VerificationController::class, 'submit'])->middleware('throttle:10,1');
    });

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Capability-gated per group. The role check is the coarse gate; the
| `permission:` middleware is the specific one, so an administrator account
| without `verify_professional` cannot reach the verification queue.
|
*/

Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::middleware('permission:verify_professional')->group(function (): void {
            Route::get('professionals', [ProfessionalVerificationController::class, 'index']);
            Route::post('professionals/{professional}/review', [ProfessionalVerificationController::class, 'review']);
            Route::post('documents/{document}/review', [ProfessionalVerificationController::class, 'reviewDocument']);
        });
    });
