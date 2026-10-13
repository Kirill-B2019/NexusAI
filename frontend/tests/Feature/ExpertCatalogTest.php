<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpertCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_experts_from_the_server_side_api_client(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts/all' => Http::response([
                [
                    'key' => 'system_architect',
                    'name' => 'Системный архитектор',
                    'description' => 'Помогает проектировать системы.',
                    'icon' => '🏗',
                    'color' => '#2563eb',
                    'keywords' => ['архитектура', 'система'],
                    'is_enabled' => true,
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.experts'))
            ->assertOk()
            ->assertSee('Каталог')
            ->assertSee('Системный архитектор')
            ->assertSee('system_architect')
            ->assertSee('архитектура')
            ->assertDontSee('server-only-test-key');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/experts/all'
            && $request->hasHeader('X-API-Key', 'server-only-test-key'));
    }

    public function test_regular_users_and_viewers_cannot_use_the_admin_experts_endpoint(): void
    {
        Http::fake();

        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.experts'))
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_missing_server_configuration_is_shown_without_making_an_api_request(): void
    {
        config([
            'services.nexus_ai.url' => null,
            'services.nexus_ai.admin_key' => null,
        ]);
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.experts'))
            ->assertOk()
            ->assertSee('адрес API')
            ->assertSee('frontend/.env');

        Http::assertNothingSent();
    }

    public function test_api_errors_are_not_exposed_as_raw_responses(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts/all' => Http::response([
                'detail' => 'raw upstream error containing private data',
            ], 403),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.experts'))
            ->assertOk()
            ->assertSee('не принял серверный admin API key')
            ->assertDontSee('raw upstream error containing private data')
            ->assertDontSee('server-only-test-key');
    }
}
