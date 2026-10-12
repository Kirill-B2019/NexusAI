<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_user_access_directory(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Участники рабочей области')
            ->assertSee($admin->email);
    }

    public function test_regular_user_and_viewer_cannot_view_admin_directory(): void
    {
        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/admin/users')
                ->assertForbidden();
        }
    }

    public function test_project_permissions_distinguish_read_only_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::User]);
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->assertTrue($admin->can('manage-users'));
        $this->assertTrue($admin->can('manage-projects'));
        $this->assertTrue($user->can('view-projects'));
        $this->assertTrue($user->can('manage-projects'));
        $this->assertFalse($user->can('manage-users'));
        $this->assertTrue($viewer->can('view-projects'));
        $this->assertFalse($viewer->can('manage-projects'));
        $this->assertFalse($viewer->can('manage-users'));
    }

    public function test_operator_can_assign_an_initial_admin_role_from_the_console(): void
    {
        $user = User::factory()->create(['email' => 'new-admin@example.com']);

        $this->artisan('app:assign-user-role', [
            'email' => $user->email,
            'role' => 'admin',
        ])->expectsOutputToContain('Администратор')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => $user->email,
            'role' => UserRole::Admin->value,
        ]);
    }
}
