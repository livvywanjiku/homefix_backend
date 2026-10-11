<?php

namespace App\Enums;

/**
 * Every capability the platform recognises.
 *
 * Permission names are the single vocabulary shared by the seeder, the
 * `permission:` route middleware, and policies. Keeping them in a backed enum
 * means a typo is a compile-time error rather than a silently unenforced gate.
 *
 * The cases below are grouped the way the administration sidebar is grouped,
 * so that a navigation entry and the endpoint behind it are gated by the same
 * name and cannot drift apart.
 */
enum Permission: string
{
    /* ── Marketplace ───────────────────────────────────────────────────── */

    case ViewBookings = 'view_bookings';
    case CreateBooking = 'create_booking';
    case AcceptBooking = 'accept_booking';
    case UpdateBookingStatus = 'update_booking_status';
    case CancelBooking = 'cancel_booking';

    case CreateServiceRequest = 'create_service_request';
    case CancelServiceRequest = 'cancel_service_request';

    case CreateQuotation = 'create_quotation';
    case ManageServices = 'manage_services';

    /* ── Administration: main ──────────────────────────────────────────── */

    case ViewDashboard = 'view_dashboard';

    case ViewUsers = 'view_users';
    case ManageUsers = 'manage_users';
    case SuspendUsers = 'suspend_users';

    case ViewProfessionals = 'view_professionals';
    case ManageProfessionals = 'manage_professionals';
    case VerifyProfessional = 'verify_professional';

    case ViewServiceRequests = 'view_service_requests';

    case ViewServices = 'view_services';
    case ViewCategories = 'view_categories';
    case ManageServiceCatalogue = 'manage_service_catalogue';

    case ManageBookings = 'manage_bookings';

    case ViewPayments = 'view_payments';
    case ManagePayments = 'manage_payments';

    case ViewReviews = 'view_reviews';
    case ModerateReviews = 'moderate_reviews';

    case ViewComplaints = 'view_complaints';
    case ManageComplaints = 'manage_complaints';

    /* ── Administration: management ────────────────────────────────────── */

    case ViewLocations = 'view_locations';
    case ManageLocations = 'manage_locations';

    case ViewAvailability = 'view_availability';
    case ViewFavorites = 'view_favorites';
    case ViewNotifications = 'view_notifications';

    /* ── Administration: analytics ─────────────────────────────────────── */

    case ViewAnalytics = 'view_analytics';
    case ViewReports = 'view_reports';
    case ExportReports = 'export_reports';

    /* ── Administration: system ────────────────────────────────────────── */

    case ManageAdministrators = 'manage_administrators';
    case ManageRoles = 'manage_roles';
    case ManagePermissions = 'manage_permissions';
    case ViewAuditLogs = 'view_audit_logs';
    case ManageSettings = 'manage_settings';

    /**
     * Human-readable label, for the roles and permissions screens.
     *
     * Derived from the name rather than declared per case: an administrator
     * reading "Verify professional" beside `verify_professional` is reading the
     * same words twice, and a hand-written label would only be a second place
     * for the two to disagree.
     */
    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * The permission names, for seeding and for `syncPermissions()`.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }

    /**
     * The permission names for a set of cases.
     *
     * @param  array<int, self>  $permissions
     * @return array<int, string>
     */
    public static function valuesOf(array $permissions): array
    {
        return array_map(fn (self $permission): string => $permission->value, $permissions);
    }
}
