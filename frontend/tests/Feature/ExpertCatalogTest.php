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
                    'system_prompt' => 'Ты системный архитектор и проектируешь системы.',
                    'icon' => '🏗',
                    'color' => '#2563eb',
                    'keywords' => ['архитектура', 'система'],
                    'sort_order' => 10,
                    'is_enabled' => true,
                    'is_system' => true,
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.experts'))
            ->assertOk()
            ->assertSee('Каталог')
            ->assertSee('Системный архитектор')
            ->assertSee('Помогает проектировать системы.')
            ->assertSee('systemPrompt')
            ->assertSee('system_architect')
            ->assertSee('архитектура')
            ->assertSee('СИСТЕМНЫЙ')
            ->assertSee('Изменить')
            ->assertSee('name="system_prompt"', false)
            ->assertSee('name="sort_order"', false)
            ->assertSee('name="is_enabled"', false)
            ->assertSee('class="checkbox-label"', false)
            ->assertSee('id="editEnabled"', false)
            ->assertSee("document.getElementById('editEnabled').checked = expert.enabled === true;", false)
            ->assertDontSee(route('admin.experts.destroy', ['expertKey' => 'system_architect']))
            ->assertDontSee('server-only-test-key');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/experts/all'
            && $request->hasHeader('X-API-Key', 'server-only-test-key'));
    }

    public function test_regular_experts_remain_deletable(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts/all' => Http::response([
                [
                    'key' => 'custom_advisor',
                    'name' => 'Пользовательский советник',
                    'system_prompt' => 'Ты пользовательский советник по продуктам.',
                    'keywords' => [],
                    'sort_order' => 100,
                    'is_enabled' => true,
                    'is_system' => false,
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.experts'))
            ->assertOk()
            ->assertSee('Пользовательский советник')
            ->assertSee(route('admin.experts.destroy', ['expertKey' => 'custom_advisor']))
            ->assertDontSee('СИСТЕМНЫЙ');
    }

    public function test_expert_creation_sends_only_fields_supported_by_nexus_api(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts' => Http::response([
                'key' => 'custom_advisor',
                'name' => 'Пользовательский советник',
                'description' => 'Описание с AI сервера.',
                'system_prompt' => 'A valid system prompt for this advisor.',
                'keywords' => ['продукт', 'планирование'],
                'icon' => '✳',
                'color' => '#2563eb',
                'sort_order' => 30,
                'is_enabled' => true,
                'is_system' => false,
                'metadata' => [],
            ], 201),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.experts.store'), [
                'key' => 'custom_advisor',
                'name' => 'Пользовательский советник',
                'description' => 'Описание с AI сервера.',
                'system_prompt' => 'A valid system prompt for this advisor.',
                'keywords' => 'продукт, планирование',
                'icon' => '✳',
                'color' => '#2563eb',
                'sort_order' => 30,
                'is_enabled' => '1',
                'is_system' => true,
            ])
            ->assertRedirect(route('admin.experts'))
            ->assertSessionHas('status', 'expert_created');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/experts'
            && $request->data() === [
                'key' => 'custom_advisor',
                'name' => 'Пользовательский советник',
                'description' => 'Описание с AI сервера.',
                'system_prompt' => 'A valid system prompt for this advisor.',
                'keywords' => ['продукт', 'планирование'],
                'icon' => '✳',
                'color' => '#2563eb',
                'sort_order' => 30,
                'is_enabled' => true,
            ]
            && $request->hasHeader('X-API-Key', 'server-only-test-key'));
    }

    public function test_expert_edits_are_saved_to_nexus_using_patch_fields(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts/custom_advisor' => Http::response([
                'key' => 'custom_advisor',
                'name' => 'Обновлённый советник',
                'description' => 'Обновлённое описание.',
                'system_prompt' => 'Updated system prompt for this advisor.',
                'keywords' => ['обновление', 'API'],
                'icon' => '✳',
                'color' => '#16a34a',
                'sort_order' => 25,
                'is_enabled' => false,
                'is_system' => false,
                'metadata' => [],
            ]),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('admin.experts.update', ['expertKey' => 'custom_advisor']), [
                'name' => 'Обновлённый советник',
                'description' => 'Обновлённое описание.',
                'system_prompt' => 'Updated system prompt for this advisor.',
                'keywords' => 'обновление, API',
                'icon' => '✳',
                'color' => '#16a34a',
                'sort_order' => 25,
                'is_enabled' => '0',
            ])
            ->assertRedirect(route('admin.experts'))
            ->assertSessionHas('status', 'expert_updated');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://nexus.test/v1/experts/custom_advisor'
            && $request->data() === [
                'name' => 'Обновлённый советник',
                'description' => 'Обновлённое описание.',
                'system_prompt' => 'Updated system prompt for this advisor.',
                'keywords' => ['обновление', 'API'],
                'icon' => '✳',
                'color' => '#16a34a',
                'sort_order' => 25,
                'is_enabled' => false,
            ]
            && $request->hasHeader('X-API-Key', 'server-only-test-key'));
    }

    public function test_upstream_rejection_does_not_report_system_expert_as_deleted(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'server-only-test-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/experts/system_architect' => Http::response([
                'detail' => 'Cannot delete system expert. Disable it instead.',
            ], 403),
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->delete(route('admin.experts.destroy', ['expertKey' => 'system_architect']))
            ->assertRedirect()
            ->assertSessionHasErrors('delete')
            ->assertSessionMissing('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://nexus.test/v1/experts/system_architect'
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
