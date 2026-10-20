<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardRoadmapTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_admin_can_continue_to_project_management_from_the_roadmap(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        Http::fake([
            'https://nexus.test/api/v1/experts/all' => Http::response(['experts' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Подключение к NEXUS AI готово')
            ->assertSee('Запустить работу с проектами')
            ->assertSee('step-item is-complete', false)
            ->assertSee('ГОТОВО')
            ->assertDontSee('МОЖНО НАЧАТЬ')
            ->assertSee(route('admin.projects'))
            ->assertSee('Открыть управление проектами')
            ->assertSee('permission-row-allowed', false)
            ->assertSee('ДОСТУП РАЗРЕШЁН')
            ->assertDontSee('permission-row-muted', false)
            ->assertDontSee('Следующий шаг — подключение к API NEXUS AI');
    }

    public function test_user_can_continue_to_assigned_projects_when_nexus_is_connected(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        Http::fake([
            'https://nexus.test/api/v1/experts/all' => Http::response(['experts' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::User]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ГОТОВО')
            ->assertSee('Запустить работу с проектами')
            ->assertSee(route('projects'))
            ->assertSee('Открыть мои проекты')
            ->assertSee('permission-row-muted', false)
            ->assertSee('ТРЕБУЕТСЯ АДМИНИСТРАТОР')
            ->assertDontSee('permission-row-allowed', false)
            ->assertDontSee('Настроить доступ');
    }

    public function test_viewer_sees_user_management_as_restricted(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        Http::fake([
            'https://nexus.test/api/v1/experts/all' => Http::response(['experts' => []]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('permission-row-muted', false)
            ->assertSee('ТРЕБУЕТСЯ АДМИНИСТРАТОР')
            ->assertDontSee('permission-row-allowed', false)
            ->assertSee('Доступом к учётным записям управляет администратор.')
            ->assertDontSee('Настроить доступ');
    }
}
