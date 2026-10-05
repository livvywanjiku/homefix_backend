<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case Professional = 'professional';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Customer => 'Customer',
            self::Professional => 'Service Professional',
        };
    }

    /**
     * Roles a visitor is permitted to assign themselves through the public API.
     *
     * The admin role is deliberately excluded: it is seeded only, never
     * self-assignable, so registration can never escalate privilege.
     *
     * @return array<int, self>
     */
    public static function selfAssignable(): array
    {
        return [self::Customer, self::Professional];
    }

    /**
     * Backing values of the self-assignable roles, for use in validation rules.
     *
     * @return array<int, string>
     */
    public static function selfAssignableValues(): array
    {
        return array_map(fn (self $role): string => $role->value, self::selfAssignable());
    }

    /**
     * The permissions this role is granted.
     *
     * Declared here rather than in the seeder so that the grant table is a
     * single source of truth that tests can assert against.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),

            self::Customer => [
                Permission::CreateServiceRequest,
                Permission::CancelServiceRequest,
                Permission::ViewBookings,
                Permission::CreateBooking,
                Permission::AcceptBooking,
                Permission::CancelBooking,
            ],

            self::Professional => [
                Permission::CreateQuotation,
                Permission::ManageServices,
                Permission::ViewBookings,
                Permission::AcceptBooking,
                Permission::UpdateBookingStatus,
            ],
        };
    }

    /**
     * Backing values of this role's permissions, for `syncPermissions()`.
     *
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return array_map(fn (Permission $permission): string => $permission->value, $this->permissions());
    }
}
