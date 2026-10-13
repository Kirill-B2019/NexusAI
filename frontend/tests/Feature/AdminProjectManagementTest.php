<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-management-key',
        ]);
    }

    public function test_admin_creates_a_nexus_project_and_stores_its_integration_key_encrypted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $projectKeyId = '2a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $projectKey = 'nx-secret-generated-by-nexus';

        Http::fake(function (Request $request) use ($projectKeyId, $projectKey) {
            if ($request->method() === 'POST' && $request->url() === 'https://nexus.test/v1/projects') {
                return Http::response($this->remoteProject(), 201);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys') {
                return Http::response(['id' => $projectKeyId, 'key' => $projectKey], 201);
            }

            return Http::response([], 404);
        });

        $this->actingAs($admin)
            ->post(route('admin.projects.store'), [
                'owner_user_id' => $owner->id,
                'name' => 'Project created',
                'description' => 'Test description',
                'metadata' => '{"team":"research"}',
            ])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.api_key');

        $project = Project::query()->where('nexus_project_id', self::PROJECT_ID)->firstOrFail();
        $this->assertSame($owner->id, $project->owner_user_id);
        $this->assertSame($projectKeyId, $project->nexus_api_key_id);
        $this->assertSame($projectKey, $project->api_key);
        $this->assertNotSame($projectKey, $project->getRawOriginal('api_key'));
        $this->assertStringNotContainsString($projectKey, $project->toJson());
        $this->assertDatabaseMissing('projects', ['api_key' => $projectKey]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/projects'
            && $request->hasHeader('X-API-Key', 'admin-management-key')
            && $request->data()['name'] === 'Project created');
    }

    public function test_admin_updates_remote_project_and_local_assignment_without_exposing_keys(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $secret = 'integration-key-never-rendered';
        $project = Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Old name',
            'description' => null,
            'metadata' => [],
            'nexus_project_id' => self::PROJECT_ID,
            'api_key' => $secret,
            'nexus_api_key_id' => '4a216802-1949-4e0b-9a3e-7bbfcb2fe982',
        ]);

        Http::fake([
            'https://nexus.test/v1/projects/'.self::PROJECT_ID => Http::response([
                ...$this->remoteProject(),
                'name' => 'Renamed project',
                'description' => 'Updated description',
                'metadata' => ['team' => 'platform'],
            ]),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.projects.update', self::PROJECT_ID), [
                'name' => 'Renamed project',
                'description' => 'Updated description',
                'metadata' => '{"team":"platform"}',
            ])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status');

        $project->refresh();
        $this->assertSame('Renamed project', $project->name);
        $this->assertSame(['team' => 'platform'], $project->metadata);
        $this->assertSame($secret, $project->api_key);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID
            && $request->data()['name'] === 'Renamed project');
    }

    public function test_project_link_issues_project_scoped_key_before_persisting_assignment(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $projectKeyId = '5a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $projectKey = 'nx-another-generated-secret';

        Http::fake([
            'https://nexus.test/v1/projects/'.self::PROJECT_ID => Http::response($this->remoteProject()),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response([
                'id' => $projectKeyId,
                'key' => $projectKey,
            ], 201),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.projects.link', self::PROJECT_ID), ['owner_user_id' => $owner->id])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status');

        $project = Project::query()->where('nexus_project_id', self::PROJECT_ID)->firstOrFail();
        $this->assertSame($owner->id, $project->owner_user_id);
        $this->assertSame($projectKeyId, $project->nexus_api_key_id);
        $this->assertSame($projectKey, $project->api_key);
        $this->assertNotSame($projectKey, $project->getRawOriginal('api_key'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys'
            && $request->hasHeader('X-API-Key', 'admin-management-key'));
    }

    public function test_key_rotation_saves_the_new_encrypted_key_before_revoking_the_old_key(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $oldKeyId = '6a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $newKeyId = '7a216802-1949-4e0b-9a3e-7bbfcb2fe982';
        $newKeySecret = 'nx-rotated-project-secret';
        $project = Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Management project',
            'nexus_project_id' => self::PROJECT_ID,
            'nexus_api_key_id' => $oldKeyId,
            'api_key' => 'old-encrypted-project-key',
        ]);

        Http::fake([
            'https://nexus.test/v1/projects/'.self::PROJECT_ID => Http::response($this->remoteProject()),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys' => Http::response([
                'id' => $newKeyId,
                'key' => $newKeySecret,
            ], 201),
            'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys/'.$oldKeyId => Http::response([], 204),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.projects.keys.rotate', self::PROJECT_ID))
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status', 'Ключ заменён; старый ключ отозван.');

        $project->refresh();
        $this->assertSame($newKeyId, $project->nexus_api_key_id);
        $this->assertSame($newKeySecret, $project->api_key);
        $this->assertNull($project->pending_revoke_key_id);
        $this->assertNotSame($newKeySecret, $project->getRawOriginal('api_key'));

        $sent = Http::recorded()
            ->map(fn (array $pair): array => [$pair[0]->method(), $pair[0]->url()])
            ->all();
        $issueIndex = array_search(['POST', 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys'], $sent, true);
        $revokeIndex = array_search(['DELETE', 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/keys/'.$oldKeyId], $sent, true);
        $this->assertIsInt($issueIndex);
        $this->assertIsInt($revokeIndex);
        $this->assertLessThan($revokeIndex, $issueIndex);
    }

    public function test_project_delete_requires_exact_name_and_only_then_deletes_nexus_and_local_mapping(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $project = Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Project from NEXUS',
            'nexus_project_id' => self::PROJECT_ID,
            'api_key' => 'project-secret',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response($this->remoteProject());
            }

            return Http::response([], 204);
        });

        $this->actingAs($admin)
            ->delete(route('admin.projects.destroy', self::PROJECT_ID), ['confirmation' => 'Wrong project'])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        $this->actingAs($admin)
            ->delete(route('admin.projects.destroy', self::PROJECT_ID), ['confirmation' => 'Project from NEXUS'])
            ->assertRedirect(route('admin.projects'))
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID
            && $request->hasHeader('X-API-Key', 'admin-management-key'));
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteProject(): array
    {
        return [
            'id' => self::PROJECT_ID,
            'name' => 'Project from NEXUS',
            'description' => 'Remote description',
            'metadata' => ['source' => 'test'],
        ];
    }
}
