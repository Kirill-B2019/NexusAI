<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_a_project_and_the_api_key_is_encrypted_at_rest(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $apiKey = 'project-scoped-test-key';

        $this->actingAs($admin)
            ->post(route('admin.projects.store'), [
                'owner_user_id' => $owner->id,
                'name' => 'Исследовательский проект',
                'nexus_project_id' => '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
                'api_key' => $apiKey,
            ])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionHas('status');

        $project = Project::query()->where('name', 'Исследовательский проект')->firstOrFail();

        $this->assertSame($owner->id, $project->owner_user_id);
        $this->assertSame($apiKey, $project->api_key);
        $this->assertNotSame($apiKey, $project->getRawOriginal('api_key'));
        $this->assertStringNotContainsString($apiKey, $project->toJson());
        $this->assertDatabaseMissing('projects', ['api_key' => $apiKey]);

        $this->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('Исследовательский проект')
            ->assertSee($owner->email)
            ->assertDontSee($apiKey);
    }

    public function test_invalid_project_form_never_flashes_the_project_key_back_to_the_browser(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $apiKey = 'never-flash-this-project-key';

        $this->actingAs($admin)
            ->from(route('admin.projects'))
            ->post(route('admin.projects.store'), [
                'owner_user_id' => 999999,
                'name' => 'Недопустимый проект',
                'nexus_project_id' => 'not-a-uuid',
                'api_key' => $apiKey,
            ])
            ->assertSessionHasErrors(['owner_user_id', 'nexus_project_id'])
            ->assertSessionMissing('_old_input.api_key');

        $this->get(route('admin.projects'))
            ->assertOk()
            ->assertDontSee($apiKey);
    }

    public function test_non_admin_cannot_create_project_key_mappings(): void
    {
        foreach ([UserRole::User, UserRole::Viewer] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.projects.store'), [
                    'owner_user_id' => 1,
                    'name' => 'Запрещённое назначение',
                    'nexus_project_id' => '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
                    'api_key' => 'project-scoped-test-key',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_users_only_see_projects_assigned_to_them(): void
    {
        $owner = User::factory()->create(['role' => UserRole::User]);
        $otherOwner = User::factory()->create(['role' => UserRole::User]);

        $ownedProject = $this->createProject($owner, 'Мой проект', '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a');
        $otherProject = $this->createProject($otherOwner, 'Чужой проект', '4a216802-1949-4e0b-9a3e-7bbfcb2fe982');
        config(['services.nexus_ai.url' => 'https://nexus.test']);

        $this->actingAs($owner)
            ->get(route('projects'))
            ->assertOk()
            ->assertSee('Мой проект')
            ->assertDontSee('Чужой проект');

        $this->get(route('chat', ['project' => $ownedProject->id]))
            ->assertOk()
            ->assertSee('Мой проект')
            ->assertDontSee('Чужой проект');

        $this->postJson(route('chat.stream'), [
            'project_id' => $otherProject->id,
            'message' => 'Неавторизованный доступ',
        ])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_viewers_can_read_the_chat_page_but_cannot_start_a_chat(): void
    {
        config(['services.nexus_ai.url' => 'https://nexus.test']);
        Http::fake();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $project = $this->createProject($viewer, 'Только чтение', '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a');

        $this->actingAs($viewer)
            ->get(route('chat', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('У вашей роли есть доступ только для чтения')
            ->assertDontSee('data-chat-form');

        $this->postJson(route('chat.stream'), [
            'project_id' => $project->id,
            'message' => 'Не отправлять',
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_chat_form_uses_only_assigned_projects_and_never_renders_credentials(): void
    {
        config(['services.nexus_ai.url' => 'https://nexus.test']);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $otherOwner = User::factory()->create(['role' => UserRole::User]);
        $projectKey = 'private-project-key-never-render';
        $project = $this->createProject(
            $owner,
            'Доступный проект',
            '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
            $projectKey,
        );
        $this->createProject($otherOwner, 'Недоступный проект', '4a216802-1949-4e0b-9a3e-7bbfcb2fe982');

        $this->actingAs($owner)
            ->get(route('chat', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('data-stream-url="'.route('chat.stream').'"', false)
            ->assertSee('value="'.$project->id.'" selected', false)
            ->assertSee('Автовыбор')
            ->assertSee('Доступный проект')
            ->assertDontSee('Недоступный проект')
            ->assertDontSee($projectKey)
            ->assertDontSee('nexus_project_id');
    }

    public function test_chat_stream_uses_the_authorized_project_key_and_forwards_sse_without_buffering(): void
    {
        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-catalog-key-must-not-be-used',
        ]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $projectKey = 'project-only-chat-key';
        $nexusProjectId = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';
        $project = $this->createProject($owner, 'Проект для чата', $nexusProjectId, $projectKey);
        $sse = "event: start\ndata: {\"experts_used\":[\"architect\"]}\n\n"
            ."event: expert_start\ndata: {\"expert\":\"architect\"}\n\n"
            ."event: token\ndata: {\"expert\":\"architect\",\"delta\":\"Привет\"}\n\n"
            ."event: reasoning\ndata: {\"expert\":\"architect\",\"delta\":\"скрытая мысль\"}\n\n"
            ."event: expert_done\ndata: {\"expert\":\"architect\"}\n\n"
            ."event: done\ndata: {\"summary\":{\"total\":1,\"ok\":1,\"failed\":0}}\n\n";

        Http::fake([
            'https://nexus.test/v1/chat/stream' => Http::response($sse, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $response = $this->actingAs($owner)->postJson(route('chat.stream'), [
            'project_id' => $project->id,
            'message' => 'Привет, эксперт',
        ])->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        $this->assertSame($sse, $response->streamedContent());
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-transform', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/v1/chat/stream'
            && $request->hasHeader('X-API-Key', $projectKey)
            && $request->data()['project_id'] === $nexusProjectId
            && $request->data()['message'] === 'Привет, эксперт');
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-API-Key', 'admin-catalog-key-must-not-be-used'));
    }

    public function test_upstream_chat_errors_do_not_leak_response_bodies_or_project_keys(): void
    {
        config(['services.nexus_ai.url' => 'https://nexus.test']);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $projectKey = 'private-project-key';
        $project = $this->createProject($owner, 'Проект', '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a', $projectKey);

        Http::fake([
            'https://nexus.test/v1/chat/stream' => Http::response([
                'detail' => 'upstream-secret-detail',
            ], 403),
        ]);

        $this->actingAs($owner)
            ->postJson(route('chat.stream'), [
                'project_id' => $project->id,
                'message' => 'Вопрос',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'NEXUS AI отклонил project API key для выбранного проекта.')
            ->assertDontSee('upstream-secret-detail')
            ->assertDontSee($projectKey);
    }

    private function createProject(User $owner, string $name, string $nexusProjectId, string $apiKey = 'test-project-key'): Project
    {
        return Project::query()->create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'nexus_project_id' => $nexusProjectId,
            'api_key' => $apiKey,
        ]);
    }
}
