<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Models\ProfessionalProfile;
use App\Models\ServiceRequest;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The administration console's account screens (spec §20): users,
 * professionals, administrators, roles, settings and the audit trail.
 *
 * The theme running through this file is that the console obeys its own rules.
 * An administrator is refused the screens reserved to the owner; an
 * administrator cannot suspend themselves or each other; the permission
 * vocabulary cannot be extended from a form; and every one of those refusals is
 * asserted by calling the API directly, because none of it is enforced by hiding
 * a button.
 */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function owner(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may reach which screen
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_cannot_read_the_user_directory(): void
    {
        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    /**
     * The four capabilities that govern the administration itself are held by
     * the owner alone. An administrator who could grant permissions could grant
     * themselves the rest, so the separation is the point of the role.
     */
    public function test_the_administration_of_the_administration_is_closed_to_an_administrator(): void
    {
        $admin = $this->admin();
        $colleague = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/administrators')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/roles')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$colleague->id}/status", [
                'action' => 'suspend',
                'reason' => 'Attempting to take the platform.',
            ])
            ->assertForbidden();
    }

    public function test_the_owner_reaches_the_reserved_screens(): void
    {
        $owner = $this->owner();

        foreach (['administrators', 'roles', 'settings'] as $path) {
            $this->actingAs($owner, 'sanctum')
                ->getJson("/api/admin/{$path}")
                ->assertOk();
        }
    }

    /*
     * Reading the trail is not reserved — an administrator needs to be able to
     * see what has been done — but acting on the administration is.
     */
    public function test_an_administrator_may_read_the_audit_trail(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The user directory
    |--------------------------------------------------------------------------
    */

    public function test_the_user_list_can_be_narrowed_by_role_and_standing(): void
    {
        User::factory()->customer()->count(2)->create();
        User::factory()->customer()->suspended()->create();
        User::factory()->professional()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users?role=customer&status=suspended')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(UserStatus::Suspended->value, $response->json('data.0.status'));
    }

    public function test_a_search_matches_the_three_things_an_administrator_has(): void
    {
        $wanted = User::factory()->customer()->create(['name' => 'Amina Wanjiru']);
        User::factory()->customer()->count(3)->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users?q=Amina')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($wanted->id, $response->json('data.0.id'));
    }

    /*
     * The sidebar is built from what the administrator may do, and it reads that
     * from their own account. Sending it for every row of a fifty-row page would
     * be fifty permission loads to render one menu, which is why the resource
     * restricts it to the viewer's own row — asserted here so that restriction
     * is a decision rather than an accident.
     */
    public function test_a_row_carries_grants_only_when_it_is_the_administrator_reading_it(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();

        $mine = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?q='.urlencode($admin->email))
            ->assertOk()
            ->json('data.0');

        $this->assertContains(UserRole::Admin->value, $mine['roles']);
        $this->assertContains(Permission::ViewDashboard->value, $mine['permissions']);

        $theirs = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?q='.urlencode($customer->email))
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('roles', $theirs);
        $this->assertArrayNotHasKey('permissions', $theirs);
    }

    /*
    |--------------------------------------------------------------------------
    | Suspension
    |--------------------------------------------------------------------------
    */

    public function test_a_suspension_must_state_its_reason(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/users/{$customer->id}/status", ['action' => 'suspend'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(UserStatus::Active, $customer->refresh()->status);
    }

    public function test_an_administrator_cannot_suspend_their_own_account(): void
    {
        $admin = $this->admin();

        // The one action that can lock the platform's operator out of the
        // screen they would use to undo it.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$admin->id}/status", [
                'action' => 'suspend',
                'reason' => 'Testing the guard.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame(UserStatus::Active, $admin->refresh()->status);
    }

    public function test_an_administrator_cannot_suspend_a_colleague(): void
    {
        $admin = $this->admin();
        $colleague = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$colleague->id}/status", [
                'action' => 'suspend',
                'reason' => 'A takeover attempt.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame(UserStatus::Active, $colleague->refresh()->status);
    }

    public function test_the_owner_may_suspend_an_ordinary_administrator(): void
    {
        $colleague = $this->admin();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/admin/users/{$colleague->id}/status", [
                'action' => 'suspend',
                'reason' => 'Repeated policy breaches.',
            ])
            ->assertOk();

        $this->assertSame(UserStatus::Suspended, $colleague->refresh()->status);
        $this->assertSame('Repeated policy breaches.', $colleague->suspension_reason);
    }

    /**
     * A super administrator account is the last way back into a platform whose
     * other administrative access has gone wrong. Removing one is a deliberate
     * database operation, not a button.
     */
    public function test_a_super_administrator_account_cannot_be_suspended_from_the_console(): void
    {
        $owner = $this->owner();
        $second = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/admin/users/{$second->id}/status", [
                'action' => 'suspend',
                'reason' => 'A dispute between owners.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame(UserStatus::Active, $second->refresh()->status);
    }

    public function test_reinstating_clears_the_reason_it_was_suspended_for(): void
    {
        $customer = User::factory()->customer()->suspended()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/users/{$customer->id}/status", ['action' => 'activate'])
            ->assertOk();

        $customer->refresh();

        $this->assertSame(UserStatus::Active, $customer->status);
        // A lifted suspension must not keep reporting why it once happened, or
        // the next reader thinks the account is still under it.
        $this->assertNull($customer->suspension_reason);
        $this->assertNull($customer->suspended_at);
    }

    public function test_a_suspension_is_recorded_against_the_administrator_who_made_it(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$customer->id}/status", [
                'action' => 'suspend',
                'reason' => 'Chargeback fraud.',
            ])
            ->assertOk();

        $entry = \App\Models\AdminLog::query()
            ->where('action', AdminAction::UserSuspended->value)
            ->where('subject_id', $customer->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('Chargeback fraud.', $entry->metadata['changes']['reason']['to']);
    }

    /*
    |--------------------------------------------------------------------------
    | Professionals
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_listing_can_be_suspended_and_reinstated(): void
    {
        $profile = ProfessionalProfile::factory()->verified()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/professionals/{$profile->id}/status", [
                'action' => 'suspend',
                'reason' => 'Repeated no-shows.',
            ])
            ->assertOk();

        $profile->refresh();

        $this->assertSame(VerificationStatus::Suspended, $profile->verification_status);
        // The record that this professional *was* verified is what a dispute
        // about work done before the suspension needs.
        $this->assertNotNull($profile->verified_at);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/professionals/{$profile->id}/status", ['action' => 'reinstate'])
            ->assertOk();

        $this->assertSame(VerificationStatus::Verified, $profile->refresh()->verification_status);
    }

    public function test_suspending_a_listing_is_recorded_in_the_trail(): void
    {
        $profile = ProfessionalProfile::factory()->verified()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/admin/professionals/{$profile->id}/status", [
                'action' => 'suspend',
                'reason' => 'Under investigation.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('admin_logs', [
            'action' => AdminAction::ProfessionalSuspended->value,
            'subject_id' => $profile->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    */

    public function test_the_owner_can_add_an_administrator(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/admin/administrators', [
                'name' => 'Njeri Kamau',
                'email' => 'njeri@homefix.test',
                'password' => 'Njeri-Kamau-2026',
                'password_confirmation' => 'Njeri-Kamau-2026',
                'role' => UserRole::Admin->value,
            ])
            ->assertCreated();

        $created = User::where('email', 'njeri@homefix.test')->firstOrFail();

        $this->assertTrue($created->hasRole(UserRole::Admin->value));
        $this->assertSame($created->id, $response->json('administrator.id'));

        $this->assertDatabaseHas('admin_logs', [
            'action' => AdminAction::AdministratorCreated->value,
            'subject_id' => $created->id,
        ]);
    }

    /**
     * Registration can only produce customers and professionals. If this
     * endpoint accepted any role string, "administrator" would become a label
     * anyone could mint.
     */
    public function test_an_administrator_cannot_be_created_with_a_marketplace_role(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/admin/administrators', [
                'name' => 'Sneaky',
                'email' => 'sneaky@homefix.test',
                'password' => 'Sneaky-Account-2026',
                'password_confirmation' => 'Sneaky-Account-2026',
                'role' => UserRole::Customer->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_nobody_changes_their_own_role(): void
    {
        $owner = $this->owner();

        // The one edit that would let an administrator escalate themselves —
        // and the one that could strand them without the access to undo it.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/admin/administrators/{$owner->id}", [
                'role' => UserRole::Admin->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertTrue($owner->refresh()->hasRole(UserRole::SuperAdmin->value));
    }

    public function test_a_customer_cannot_be_edited_through_the_administrator_screen(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/admin/administrators/{$customer->id}", ['name' => 'Renamed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('administrator');

        $this->assertNotSame('Renamed', $customer->refresh()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles and permissions
    |--------------------------------------------------------------------------
    */

    public function test_the_role_matrix_offers_the_enum_and_nothing_else(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/admin/roles')
            ->assertOk();

        // The vocabulary is the enum the route middleware names. A permission
        // with no gate behind it would be a checkbox that does nothing.
        $this->assertCount(count(Permission::cases()), $response->json('permissions'));

        $roles = collect($response->json('roles'))->keyBy('name');

        $this->assertFalse($roles[UserRole::SuperAdmin->value]['is_editable']);
        $this->assertTrue($roles[UserRole::Admin->value]['is_editable']);
    }

    public function test_the_reserved_permissions_are_flagged_as_reserved(): void
    {
        $permissions = collect(
            $this->actingAs($this->owner(), 'sanctum')
                ->getJson('/api/admin/roles')
                ->json('permissions'),
        )->keyBy('value');

        foreach (UserRole::superAdminOnly() as $permission) {
            $this->assertTrue(
                $permissions[$permission->value]['reserved'],
                "{$permission->value} should be flagged reserved.",
            );
        }

        $this->assertFalse($permissions[Permission::ViewDashboard->value]['reserved']);
    }

    public function test_the_super_administrator_role_cannot_be_edited(): void
    {
        $superadmin = Role::findByName(UserRole::SuperAdmin->value);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/admin/roles/{$superadmin->id}/permissions", [
                'permissions' => [Permission::ViewDashboard->value],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    /**
     * Refused, not silently dropped. A screen that accepted the request and
     * granted less would leave an administrator believing they had delegated
     * access they had not.
     */
    public function test_a_reserved_permission_cannot_be_granted_to_another_role(): void
    {
        $customer = Role::findByName(UserRole::Customer->value);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/admin/roles/{$customer->id}/permissions", [
                'permissions' => [
                    Permission::CreateServiceRequest->value,
                    Permission::ManagePermissions->value,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->assertFalse(
            $customer->refresh()->hasPermissionTo(Permission::ManagePermissions->value),
        );
    }

    public function test_an_edited_role_is_marked_so_a_reseed_leaves_it_alone(): void
    {
        $customer = Role::findByName(UserRole::Customer->value);

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson("/api/admin/roles/{$customer->id}/permissions", [
                'permissions' => [Permission::CreateServiceRequest->value],
            ])
            ->assertOk();

        $this->assertContains(
            UserRole::Customer->value,
            Setting::get(RoleController::CUSTOMISED_SETTING, []),
        );

        // Re-seeding a live database must not silently reset a grant an
        // administrator configured by hand.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            [Permission::CreateServiceRequest->value],
            $customer->refresh()->permissions->pluck('name')->all(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    public function test_settings_arrive_grouped_for_the_screen(): void
    {
        $response = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk();

        $this->assertSame('marketplace', $response->json('groups.0.group'));
        $this->assertNotEmpty($response->json('groups.0.settings'));
    }

    /**
     * The test that makes the settings screen worth having: the value it writes
     * is read by real code, not displayed and forgotten.
     */
    public function test_a_saved_setting_changes_the_behaviour_it_governs(): void
    {
        $this->assertSame(ServiceRequest::LIFETIME_DAYS, ServiceRequest::lifetimeDays());

        $this->actingAs($this->owner(), 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => [
                    ['key' => ServiceRequest::LIFETIME_SETTING, 'value' => 45],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertSame(45, ServiceRequest::lifetimeDays());

        // And a posting made afterwards actually carries the new deadline.
        $request = ServiceRequest::factory()->create();

        $this->assertTrue(
            $request->expires_at->isSameDay(now()->addDays(45)),
            'A posting made after the change should carry the new deadline.',
        );
    }

    public function test_an_unknown_setting_cannot_be_invented(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => [['key' => 'made.up.key', 'value' => 'anything']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.0.key');

        $this->assertDatabaseMissing('settings', ['key' => 'made.up.key']);
    }

    public function test_a_setting_change_is_audited_with_the_old_value_beside_the_new(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => [['key' => ServiceRequest::LIFETIME_SETTING, 'value' => 14]],
            ])
            ->assertOk();

        $entry = \App\Models\AdminLog::query()
            ->where('action', AdminAction::SettingUpdated->value)
            ->firstOrFail();

        $change = $entry->metadata['changes'][ServiceRequest::LIFETIME_SETTING];

        $this->assertSame(ServiceRequest::LIFETIME_DAYS, $change['from']);
        $this->assertSame(14, $change['to']);
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail itself
    |--------------------------------------------------------------------------
    */

    public function test_the_trail_names_the_actor_and_can_be_filtered_by_action(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->customer()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$customer->id}/status", [
                'action' => 'suspend',
                'reason' => 'Abusive messages.',
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/audit-logs?action='.AdminAction::UserSuspended->value)
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($admin->name, $response->json('data.0.actor.name'));
        $this->assertSame(AdminAction::UserSuspended->value, $response->json('data.0.action'));
    }

    public function test_a_read_is_not_audited(): void
    {
        $admin = $this->admin();

        // The trail answers "who changed this". Logging every list view would
        // bury that answer.
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk();

        $this->assertDatabaseCount('admin_logs', 0);
    }
}
