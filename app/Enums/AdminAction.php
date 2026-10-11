<?php

namespace App\Enums;

/**
 * The administrative acts worth keeping a record of.
 *
 * A backed enum rather than free text so the audit trail can be filtered and
 * grouped without matching on prose, and so a typo in a call site is a
 * compile-time error rather than a row nobody can find again.
 *
 * The list is deliberately about *decisions* — a suspension, a verification, a
 * permission change. Reads are not audited: the trail answers "who changed
 * this", and logging every list view would bury that.
 */
enum AdminAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';

    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserSuspended = 'user_suspended';
    case UserActivated = 'user_activated';

    case ProfessionalVerified = 'professional_verified';
    case ProfessionalRejected = 'professional_rejected';
    case ProfessionalSuspended = 'professional_suspended';
    case ProfessionalActivated = 'professional_activated';

    case ServiceCreated = 'service_created';
    case ServiceUpdated = 'service_updated';
    case ServiceDeleted = 'service_deleted';

    case CategoryCreated = 'category_created';
    case CategoryUpdated = 'category_updated';
    case CategoryDeleted = 'category_deleted';

    case BookingUpdated = 'booking_updated';
    case BookingCancelled = 'booking_cancelled';

    case PaymentUpdated = 'payment_updated';
    case RefundIssued = 'refund_issued';

    case ReviewModerated = 'review_moderated';
    case ComplaintResolved = 'complaint_resolved';

    case AdministratorCreated = 'administrator_created';
    case AdministratorUpdated = 'administrator_updated';
    case AdministratorSuspended = 'administrator_suspended';

    case RoleUpdated = 'role_updated';
    case PermissionsUpdated = 'permissions_updated';

    case SettingUpdated = 'setting_updated';

    /**
     * Human-readable label, for the audit log table.
     */
    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * The badge tone this action is shown in.
     *
     * Grouped on what the action *means* to a reader scanning the trail —
     * something was taken away, something was granted, or something merely
     * changed — rather than on which area of the platform it belongs to.
     */
    public function tone(): string
    {
        return match ($this) {
            self::UserSuspended,
            self::ProfessionalRejected,
            self::ProfessionalSuspended,
            self::AdministratorSuspended,
            self::ServiceDeleted,
            self::CategoryDeleted,
            self::LoginFailed,
            self::BookingCancelled => 'danger',

            self::ProfessionalVerified,
            self::ProfessionalActivated,
            self::UserActivated,
            self::UserCreated,
            self::AdministratorCreated,
            self::ServiceCreated,
            self::CategoryCreated => 'success',

            self::RoleUpdated,
            self::PermissionsUpdated,
            self::SettingUpdated,
            self::RefundIssued,
            self::ReviewModerated,
            self::ComplaintResolved => 'accent',

            default => 'neutral',
        };
    }
}
