<?php

namespace App\Enums;

/**
 * Every capability the platform recognises.
 *
 * Permission names are the single vocabulary shared by the seeder, the
 * `permission:` route middleware, and policies. Keeping them in a backed enum
 * means a typo is a compile-time error rather than a silently unenforced gate.
 */
enum Permission: string
{
    case ViewBookings = 'view_bookings';
    case CreateBooking = 'create_booking';
    case AcceptBooking = 'accept_booking';
    case UpdateBookingStatus = 'update_booking_status';
    case CancelBooking = 'cancel_booking';

    case CreateServiceRequest = 'create_service_request';
    case CancelServiceRequest = 'cancel_service_request';

    case CreateQuotation = 'create_quotation';
    case ManageServices = 'manage_services';

    case ManageUsers = 'manage_users';
    case SuspendUsers = 'suspend_users';
    case VerifyProfessional = 'verify_professional';

    case ManageServiceCatalogue = 'manage_service_catalogue';
    case ManageLocations = 'manage_locations';
    case ManagePayments = 'manage_payments';
    case ManageComplaints = 'manage_complaints';
    case ModerateReviews = 'moderate_reviews';
    case ViewAnalytics = 'view_analytics';
    case ManageSettings = 'manage_settings';

    /**
     * The permission names, for seeding and for `syncPermissions()`.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
