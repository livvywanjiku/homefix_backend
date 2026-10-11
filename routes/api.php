<?php

use App\Http\Controllers\Api\Admin\AdministratorController;
use App\Http\Controllers\Api\Admin\AnalyticsController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BookingAdminController;
use App\Http\Controllers\Api\Admin\CatalogueAdminController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ProfessionalAdminController;
use App\Http\Controllers\Api\Admin\ProfessionalVerificationController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ServiceRequestAdminController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Catalogue\ServiceCategoryController;
use App\Http\Controllers\Api\Catalogue\ServiceController;
use App\Http\Controllers\Api\Discovery\ProfessionalSearchController;
use App\Http\Controllers\Api\Discovery\PublicProfessionalController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Professional\AvailabilityController;
use App\Http\Controllers\Api\Professional\DocumentController;
use App\Http\Controllers\Api\Professional\PortfolioController;
use App\Http\Controllers\Api\Professional\ProfileController as ProfessionalProfileController;
use App\Http\Controllers\Api\Professional\QuotationController as ProfessionalQuotationController;
use App\Http\Controllers\Api\Professional\RequestBoardController;
use App\Http\Controllers\Api\Professional\ServiceController as ProfessionalOfferingController;
use App\Http\Controllers\Api\Professional\VerificationController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ServiceRequestController;
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
| Public Professional Directory
|--------------------------------------------------------------------------
|
| Also unauthenticated: a visitor has to be able to see who is available, and
| what they charge, before being asked to create an account. Only verified
| professionals appear, and a profile that is not visible answers 404 rather
| than 403 so the directory cannot be used to enumerate applicants.
|
| Throttled because search is the one public endpoint that reads more than a
| single indexed row per request.
|
*/

Route::get('professionals', [ProfessionalSearchController::class, 'index'])->middleware('throttle:60,1');
Route::get('professionals/{professional}', [PublicProfessionalController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Customer Service Requests
|--------------------------------------------------------------------------
|
| Behind authentication and the customer role, per the guest-access rule: a
| visitor may browse but may not post a job. The `next=` round trip in the
| Next.js proxy is a convenience on top of this, not a substitute for it.
|
*/

Route::middleware(['auth:sanctum', 'role:customer'])
    ->prefix('service-requests')
    ->group(function (): void {
        Route::get('/', [ServiceRequestController::class, 'index']);
        Route::post('/', [ServiceRequestController::class, 'store'])->middleware('throttle:20,1');
        Route::get('{serviceRequest}', [ServiceRequestController::class, 'show']);
        Route::put('{serviceRequest}', [ServiceRequestController::class, 'update']);
        Route::post('{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel']);

        // Throttled because each one writes files to disk.
        Route::post('{serviceRequest}/images', [ServiceRequestController::class, 'uploadImages'])
            ->middleware('throttle:30,1');
        Route::delete('{serviceRequest}/images/{image}', [ServiceRequestController::class, 'destroyImage']);

        /*
         * The quotations received on one job.
         *
         * Read and decided, never written: a customer chooses between the prices
         * they were sent rather than naming one. Both ids are in the path so the
         * quotation can be checked against the request it is supposed to belong
         * to — pairing your own request id with somebody else's quotation id must
         * not be a way in.
         */
        Route::get('{serviceRequest}/quotations', [QuotationController::class, 'index']);
        Route::post('{serviceRequest}/quotations/{quotation}/accept', [QuotationController::class, 'accept']);
        Route::post('{serviceRequest}/quotations/{quotation}/reject', [QuotationController::class, 'reject']);
    });

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

        /*
         * The open-request board. Read-only: quoting on a request is a separate
         * flow, so nothing here can modify a customer's posting.
         */
        Route::get('requests', [RequestBoardController::class, 'index']);
        Route::get('requests/{serviceRequest}', [RequestBoardController::class, 'show']);

        /*
         * Price offers this professional has made.
         *
         * Separate from the board because they answer different questions: the
         * board is "what could I quote on", this is "what did I quote, and what
         * became of it". A job leaves the board the moment it is awarded, so
         * without this there would be nowhere to watch a bid that won.
         */
        Route::get('quotations', [ProfessionalQuotationController::class, 'index']);
        Route::post('quotations', [ProfessionalQuotationController::class, 'store'])->middleware('throttle:30,1');
        Route::get('quotations/{quotation}', [ProfessionalQuotationController::class, 'show']);
        Route::put('quotations/{quotation}', [ProfessionalQuotationController::class, 'update']);
        Route::post('quotations/{quotation}/withdraw', [ProfessionalQuotationController::class, 'withdraw']);
    });

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Every route here sits behind three gates, in this order:
|
|   1. `auth:sanctum`  — a valid personal access token.
|   2. `role:`         — the account holds an administrative role.
|   3. `permission:`   — the account holds the specific capability.
|
| The role check is the coarse gate and the permission check is the specific
| one, so an administrator without `view_payments` cannot reach the payments
| endpoint even though they are an administrator. None of this is enforced by
| hiding buttons: a hand-rolled request against these URLs is refused here.
|
| `role:admin|superadmin` is valid — Spatie's RoleMiddleware explodes the
| parameter on the pipe and accepts any of the named roles.
|
*/

Route::middleware(['auth:sanctum', 'role:admin|superadmin'])
    ->prefix('admin')
    ->group(function (): void {
        /* ── Dashboard ─────────────────────────────────────────────────── */

        Route::middleware('permission:view_dashboard')->group(function (): void {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('statistics', [DashboardController::class, 'statistics']);
            Route::get('activity', [DashboardController::class, 'activity']);
            Route::get('pending-actions', [DashboardController::class, 'pendingActions']);
            Route::get('system-health', [DashboardController::class, 'health']);
        });

        /* ── Analytics ─────────────────────────────────────────────────── */

        Route::middleware('permission:view_analytics')->group(function (): void {
            Route::get('revenue', [AnalyticsController::class, 'revenue']);
            Route::get('bookings/analytics', [AnalyticsController::class, 'bookings']);
            Route::get('users/analytics', [AnalyticsController::class, 'users']);
            Route::get('professionals/analytics', [AnalyticsController::class, 'professionals']);
            Route::get('services/analytics', [AnalyticsController::class, 'services']);
            Route::get('locations/analytics', [AnalyticsController::class, 'locations']);
        });

        /* ── Reports ───────────────────────────────────────────────────── */

        Route::middleware('permission:view_reports')->group(function (): void {
            Route::get('reports', [ReportController::class, 'index']);
            Route::get('reports/{report}', [ReportController::class, 'show']);
        });

        // Kept behind its own permission: reading a report and taking the whole
        // dataset out of the building are different privileges.
        Route::middleware('permission:export_reports')
            ->get('reports/{report}/export', [ReportController::class, 'export']);

        /* ── Users ─────────────────────────────────────────────────────── */

        Route::middleware('permission:view_users')->group(function (): void {
            Route::get('users', [UserAdminController::class, 'index']);
            Route::get('users/{user}', [UserAdminController::class, 'show']);
        });

        Route::middleware('permission:suspend_users')
            ->put('users/{user}/status', [UserAdminController::class, 'updateStatus']);

        /* ── Professionals ─────────────────────────────────────────────── */

        /*
         * Registered before `professionals/{professional}`: routes match in
         * the order they are declared, so a literal segment has to come first
         * or the wildcard would swallow it and try to bind a profile called
         * "verification-queue".
         */
        Route::middleware('permission:verify_professional')->group(function (): void {
            Route::get(
                'professionals/verification-queue',
                [ProfessionalVerificationController::class, 'index'],
            );
            Route::post(
                'professionals/{professional}/review',
                [ProfessionalVerificationController::class, 'review'],
            );
            Route::post(
                'documents/{document}/review',
                [ProfessionalVerificationController::class, 'reviewDocument'],
            );
        });

        Route::middleware('permission:view_professionals')->group(function (): void {
            Route::get('professionals', [ProfessionalAdminController::class, 'index']);
            Route::get('professionals/{professional}', [ProfessionalAdminController::class, 'show']);
        });

        Route::middleware('permission:manage_professionals')->group(function (): void {
            Route::put(
                'professionals/{professional}/status',
                [ProfessionalAdminController::class, 'updateStatus'],
            );
            Route::post(
                'professionals/{professional}/suspend-account',
                [ProfessionalAdminController::class, 'suspendAccount'],
            );
        });

        /* ── Service requests ──────────────────────────────────────────── */

        Route::middleware('permission:view_service_requests')->group(function (): void {
            Route::get('service-requests', [ServiceRequestAdminController::class, 'index']);
            Route::get('service-requests/{serviceRequest}', [ServiceRequestAdminController::class, 'show']);
        });

        /* ── Bookings ──────────────────────────────────────────────────── */

        Route::middleware('permission:view_bookings')->group(function (): void {
            Route::get('bookings', [BookingAdminController::class, 'index']);
            Route::get('bookings/{booking}', [BookingAdminController::class, 'show']);
            Route::get('bookings/{booking}/transitions', [BookingAdminController::class, 'transitions']);
        });

        Route::middleware('permission:manage_bookings')
            ->post('bookings/{booking}/transition', [BookingAdminController::class, 'transition']);

        /* ── Service catalogue ─────────────────────────────────────────── */

        Route::middleware('permission:view_categories')->group(function (): void {
            Route::get('categories', [CatalogueAdminController::class, 'categories']);
        });

        Route::middleware('permission:view_services')->group(function (): void {
            Route::get('services', [CatalogueAdminController::class, 'services']);
        });

        Route::middleware('permission:manage_service_catalogue')->group(function (): void {
            Route::post('categories', [CatalogueAdminController::class, 'storeCategory']);
            Route::put('categories/{category}', [CatalogueAdminController::class, 'updateCategory']);
            Route::delete('categories/{category}', [CatalogueAdminController::class, 'destroyCategory']);

            Route::post('services', [CatalogueAdminController::class, 'storeService']);
            Route::put('services/{service}', [CatalogueAdminController::class, 'updateService']);
            Route::delete('services/{service}', [CatalogueAdminController::class, 'destroyService']);
        });

        /* ── Administrators, roles and permissions ─────────────────────── */

        Route::middleware('permission:manage_administrators')->group(function (): void {
            Route::get('administrators', [AdministratorController::class, 'index']);
            Route::post('administrators', [AdministratorController::class, 'store']);
            Route::put('administrators/{administrator}', [AdministratorController::class, 'update']);
        });

        Route::middleware('permission:manage_permissions')->group(function (): void {
            Route::get('roles', [RoleController::class, 'index']);
            Route::put('roles/{role}/permissions', [RoleController::class, 'update']);
        });

        /* ── Audit trail and settings ──────────────────────────────────── */

        Route::middleware('permission:view_audit_logs')->group(function (): void {
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('audit-logs/actors', [AuditLogController::class, 'actors']);
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:manage_settings');

        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:manage_settings');
    });
