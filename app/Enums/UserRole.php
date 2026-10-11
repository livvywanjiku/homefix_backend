<?php

namespace App\Enums;

enum UserRole: string
{
    /**
     * The account that runs the platform.
     *
     * Held by the seeded owner account only, and never self-assignable. It
     * differs from `Admin` in one direction only: it holds every permission,
     * including the ones that govern administrators, roles and platform
     * settings, which an ordinary administrator must not be able to change.
     */
    case SuperAdmin = 'superadmin';

    case Admin = 'admin';
    case Customer = 'customer';
    case Professional = 'professional';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::Admin => 'Administrator',
            self::Customer => 'Customer',
            self::Professional => 'Service Professional',
        };
    }

    /**
     * Every role that may enter the administration area.
     *
     * Used by the route middleware and by `User::isAdmin()`, so that adding a
     * third administrative role is one edit rather than a search for every
     * place the string 'admin' appears.
     *
     * @return array<int, self>
     */
    public static function administrative(): array
    {
        return [self::SuperAdmin, self::Admin];
    }

    /**
     * Backing values of the administrative roles, for `role:` middleware.
     *
     * @return array<int, string>
     */
    public static function administrativeValues(): array
    {
        return array_map(fn (self $role): string => $role->value, self::administrative());
    }

    /**
     * Roles a visitor is permitted to assign themselves through the public API.
     *
     * Every administrative role is deliberately excluded: they are seeded only,
     * never self-assignable, so registration can never escalate privilege.
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
            /*
             * Everything, without exception. Written as a subtraction for the
             * one case below rather than a list here, so a permission added to
             * the enum is granted to the super administrator automatically and
             * cannot be forgotten.
             */
            self::SuperAdmin => Permission::cases(),

            /*
             * The operational platform: every area an administrator runs day to
             * day, minus the four that govern the administration itself. An
             * administrator who could grant permissions could grant themselves
             * the rest, which would make the distinction meaningless.
             */
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => ! in_array($permission, self::superAdminOnly(), true),
            )),

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
     * The permissions reserved to the super administrator.
     *
     * @return array<int, Permission>
     */
    public static function superAdminOnly(): array
    {
        return [
            Permission::ManageAdministrators,
            Permission::ManageRoles,
            Permission::ManagePermissions,
            Permission::ManageSettings,
        ];
    }

    /**
     * Backing values of this role's permissions, for `syncPermissions()`.
     *
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return Permission::valuesOf($this->permissions());
    }
}
