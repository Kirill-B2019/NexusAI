<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->assertSee('Добавить пользователя')
            ->assertSee('class="modal-dialog"', false)
            ->assertDontSee('admin-create-panel', false)
            ->assertSee('name="role"', false)
            ->assertSee('Только чтение')
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

    public function test_admin_can_create_users_with_any_supported_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Тестовый просмотрщик',
            'email' => 'viewer@example.com',
            'role' => UserRole::Viewer->value,
        ])->assertRedirect('/admin/users')
            ->assertSessionHas('new_user_credentials');

        $temporaryPassword = $response->getSession()->get('new_user_credentials.password');
        $user = User::query()->where('email', 'viewer@example.com')->firstOrFail();

        $this->assertSame(UserRole::Viewer, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->get('/admin/users')->assertSee(e($temporaryPassword), false);
    }

    public function test_non_admin_cannot_create_accounts(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($user)->post('/admin/users', [
            'name' => 'Unauthorized',
            'email' => 'unauthorized@example.com',
            'role' => UserRole::Admin->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.com']);
    }

    public function test_invalid_user_input_reopens_the_modal_with_validation_errors(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['email' => 'existing@example.com']);

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Повторный адрес',
            'email' => 'existing@example.com',
            'role' => UserRole::User->value,
        ])->assertSessionHasErrors('email');

        $this->get('/admin/users')
            ->assertOk()
            ->assertSee('showModal()', false)
            ->assertSee('Это значение поля электронная почта уже используется.');
    }

    public function test_new_user_must_change_temporary_password_before_using_the_app(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/account/password/change');

        $this->get('/account/password/change')
            ->assertOk()
            ->assertSee('Задайте свой пароль');

        $this->post('/account/password/change', [
            'password' => 'New-user-password-2026!',
            'password_confirmation' => 'New-user-password-2026!',
        ])->assertRedirect('/dashboard');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('New-user-password-2026!', $user->fresh()->password));
        $this->get('/dashboard')->assertOk();
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
