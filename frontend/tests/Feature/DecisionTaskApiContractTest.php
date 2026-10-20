<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DecisionTaskApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_decision_creation_sends_content_to_the_api(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['decisions' => [[
                    'id' => 'decision-1',
                    'title' => 'Архитектура',
                    'content' => 'Используем Laravel.',
                    'status' => 'active',
                ]]]);
            }

            return Http::response([
                'id' => 'decision-1',
                'title' => 'Архитектура',
                'content' => 'Используем Laravel.',
                'status' => 'active',
            ], 201);
        });

        $this->get(route('admin.decisions', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertOk()
            ->assertSee('Используем Laravel.')
            ->assertSee('name="content"', false)
            ->assertSee('minlength="2"', false)
            ->assertSee('maxlength="300"', false)
            ->assertSee('role="region"', false)
            ->assertSee('value="superseded"', false)
            ->assertDontSee('value="resolved"', false);

        $this->post(route('admin.decisions.store', ['nexusProjectId' => self::PROJECT_ID]), [
            'title' => 'Архитектура',
            'content' => 'Используем Laravel.',
        ])->assertRedirect(route('admin.decisions', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertSessionHas('status', 'decision_created');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/decisions'
            && $request->data()['content'] === 'Используем Laravel.'
            && ! array_key_exists('description', $request->data()));
    }

    public function test_decision_update_sends_content_and_api_status(): void
    {
        Http::fake([
            'https://nexus.test/v1/decisions/*' => Http::response([
                'id' => 'decision-1',
                'title' => 'Обновлённая архитектура',
                'content' => 'Перешли на Laravel.',
                'status' => 'superseded',
            ]),
        ]);

        $this->patch(route('admin.decisions.update', ['decisionId' => 'decision-1']), [
            'title' => 'Обновлённая архитектура',
            'content' => 'Перешли на Laravel.',
            'status' => 'superseded',
        ])->assertRedirect()
            ->assertSessionHas('status', 'decision_updated');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://nexus.test/v1/decisions/decision-1'
            && $request->data()['content'] === 'Перешли на Laravel.'
            && $request->data()['status'] === 'superseded');
    }

    public function test_task_form_and_creation_use_assignee_expert_api_field(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['tasks' => [[
                    'id' => 'existing-task',
                    'title' => 'Подготовить релиз',
                    'description' => null,
                    'status' => 'open',
                    'assignee_expert' => 'architect',
                    'due_date' => null,
                ]]]);
            }

            return Http::response([
                'id' => 'task-1',
                'title' => 'Подготовить релиз',
                'description' => null,
                'status' => 'open',
                'assignee_expert' => 'architect',
                'due_date' => null,
            ], 201);
        });

        $this->get(route('admin.tasks', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertOk()
            ->assertSee('name="assignee_expert"', false)
            ->assertSee('Ключ эксперта-исполнителя')
            ->assertSee('maxlength="300"', false)
            ->assertSee('role="region"', false)
            ->assertSee('value="cancelled"', false)
            ->assertDontSee('value="blocked"', false);

        $this->post(route('admin.tasks.store', ['nexusProjectId' => self::PROJECT_ID]), [
            'title' => 'Подготовить релиз',
            'assignee_expert' => 'architect',
        ])->assertRedirect(route('admin.tasks', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertSessionHas('status', 'task_created');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/v1/projects/'.self::PROJECT_ID.'/tasks'
            && $request->data()['assignee_expert'] === 'architect'
            && ! array_key_exists('assignee', $request->data()));
    }

    public function test_decision_and_task_title_validation_matches_fastapi_limits(): void
    {
        Http::fake();
        $longTitle = str_repeat('a', 301);

        foreach (['x', $longTitle] as $title) {
            $this->post(route('admin.decisions.store', ['nexusProjectId' => self::PROJECT_ID]), [
                'title' => $title,
                'content' => 'Решение.',
            ])->assertSessionHasErrors('title');

            $this->post(route('admin.tasks.store', ['nexusProjectId' => self::PROJECT_ID]), [
                'title' => $title,
            ])->assertSessionHasErrors('title');
        }

        Http::assertNothingSent();
    }

    public function test_task_update_accepts_cancelled_and_clears_assignee_and_due_date(): void
    {
        Http::fake([
            'https://nexus.test/v1/tasks/*' => Http::response([
                'id' => 'task-1',
                'title' => 'Подготовить релиз',
                'description' => null,
                'status' => 'cancelled',
                'assignee_expert' => null,
                'due_date' => null,
            ]),
        ]);

        $this->patch(route('admin.tasks.update', ['taskId' => 'task-1']), [
            'title' => 'Подготовить релиз',
            'description' => '',
            'status' => 'cancelled',
            'assignee_expert' => '',
            'due_date' => '',
        ])->assertRedirect()
            ->assertSessionHas('status', 'task_updated');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://nexus.test/v1/tasks/task-1'
            && $request->data()['status'] === 'cancelled'
            && array_key_exists('assignee_expert', $request->data())
            && $request->data()['assignee_expert'] === null
            && array_key_exists('due_date', $request->data())
            && $request->data()['due_date'] === null);
    }
}
