<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectAssignmentModalTest extends TestCase
{
    use RefreshDatabase;

    private const ASSIGNED_PROJECT_ID = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';

    private const UNASSIGNED_PROJECT_ID = '4a216802-1949-4e0b-9a3e-7bbfcb2fe982';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
    }

    public function test_project_assignments_use_an_accessible_native_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User, 'name' => 'Project Owner']);
        Project::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Assigned project',
            'nexus_project_id' => self::ASSIGNED_PROJECT_ID,
            'nexus_api_key_id' => '5a216802-1949-4e0b-9a3e-7bbfcb2fe982',
            'api_key' => 'encrypted-server-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response(['projects' => [
                $this->remoteProject(self::ASSIGNED_PROJECT_ID, 'Assigned project'),
                $this->remoteProject(self::UNASSIGNED_PROJECT_ID, 'Unassigned project'),
            ]]),
            'https://nexus.test/v1/projects/*/keys' => Http::response(['keys' => []]),
        ]);

        $directory = $this->actingAs($admin)->get(route('admin.projects'));

        $directory->assertOk()
            ->assertSee('Assigned project')
            ->assertSee('Unassigned project')
            ->assertSee(route('admin.projects.show', self::ASSIGNED_PROJECT_ID))
            ->assertDontSee('aria-haspopup="dialog"', false);

        $response = $this->get(route('admin.projects.show', self::ASSIGNED_PROJECT_ID));

        $response->assertOk()
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('class="quick-link-icon"', false)
            ->assertSee('<svg viewBox="0 0 24 24" focusable="false">', false)
            ->assertSee('aria-labelledby="assignment-title-0"', false)
            ->assertSee('aria-describedby="assignment-description-0"', false)
            ->assertSee('@click.self="$el.close()"', false)
            ->assertSee('autofocus', false)
            ->assertSee('aria-label="Закрыть окно назначения"', false)
            ->assertSee('name="owner_user_id"', false)
            ->assertSee('value="'.$owner->id.'" selected', false)
            ->assertSee('Снять назначение')
            ->assertSee('Project Owner')
            ->assertSee('is-active" href="'.route('admin.projects').'"', false)
            ->assertDontSee('encrypted-server-key');

        $this->assertSame(1, substr_count($response->getContent(), 'id="assignment-dialog-'));

        $this->get(route('admin.projects.show', self::UNASSIGNED_PROJECT_ID))
            ->assertOk()
            ->assertSee('Назначить пользователя и подключить чат')
            ->assertSee('Без назначения пользователи Laravel не имеют доступа к проекту.');
    }

    public function test_invalid_assignment_reopens_only_the_related_modal_and_shows_validation_error(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Project::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Assigned project',
            'nexus_project_id' => self::ASSIGNED_PROJECT_ID,
            'api_key' => 'encrypted-server-key',
        ]);
        Http::fake([
            'https://nexus.test/v1/projects' => Http::response(['projects' => [
                $this->remoteProject(self::ASSIGNED_PROJECT_ID, 'Assigned project'),
                $this->remoteProject(self::UNASSIGNED_PROJECT_ID, 'Unassigned project'),
            ]]),
            'https://nexus.test/v1/projects/*/keys' => Http::response(['keys' => []]),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.projects.show', self::ASSIGNED_PROJECT_ID))
            ->put(route('admin.projects.assignment', self::ASSIGNED_PROJECT_ID), [
                'assignment_project_id' => self::ASSIGNED_PROJECT_ID,
                'owner_user_id' => 999999,
            ])
            ->assertSessionHasErrors('owner_user_id');

        $response = $this->get(route('admin.projects.show', self::ASSIGNED_PROJECT_ID));

        $response->assertOk()
            ->assertSee('x-init="$nextTick(() => $el.showModal())"', false)
            ->assertSee('The selected owner user id is invalid.')
            ->assertSee('role="alert"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'x-init="$nextTick(() => $el.showModal())"'));
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteProject(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'description' => null,
            'metadata' => [],
        ];
    }
}
