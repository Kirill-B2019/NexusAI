<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_health_page_displays_fastapi_service_health(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/admin/system-health' => Http::response([
                'status' => 'ok',
                'services' => [
                    'api' => ['status' => 'ok'],
                    'model' => ['status' => 'error', 'error' => 'model unavailable'],
                ],
                'timestamp' => 1_760_000_000,
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.health'))
            ->assertOk()
            ->assertSee('Система деградирована')
            ->assertSee('Api')
            ->assertSee('Здоров')
            ->assertSee('Model')
            ->assertSee('Недоступен')
            ->assertSee('model unavailable');
    }
}
