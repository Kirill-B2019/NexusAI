<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrainingCoverageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_expert_page_shows_topic_coverage_progress_and_gaps(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($request->url(), '/experts/all')) {
                return Http::response(['experts' => [$this->expertPayload()]]);
            }
            if ($path === '/api/v1/admin/training/datasets/stats') {
                return Http::response($this->statsPayload());
            }
            if ($path === '/api/v1/admin/training/datasets/releases') {
                return Http::response(['releases' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
            }
            if ($path === '/api/v1/admin/training/datasets/batches') {
                return Http::response(['batches' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
            }
            if ($path === '/api/v1/admin/training/adapters/') {
                return Http::response(['adapters' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $this->get(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertOk()
            ->assertSee('Прогресс набора данных')
            ->assertSee('3 / 59 утверждённых пар')
            ->assertSee('Не хватает тем: api_gateway')
            ->assertSee('class="coverage-bar"', false)
            ->assertSee('name="topic"', false)
            ->assertSee('caching · покрыта')
            ->assertSee('api_gateway');

        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH)
            === '/api/v1/admin/training/datasets/stats'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_page_still_renders_when_stats_endpoint_is_unavailable(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($request->url(), '/experts/all')) {
                return Http::response(['experts' => [$this->expertPayload()]]);
            }
            if ($path === '/api/v1/admin/training/datasets/stats') {
                return Http::response(['detail' => 'older api image'], 404);
            }
            if (in_array($path, [
                '/api/v1/admin/training/datasets/releases',
                '/api/v1/admin/training/datasets/batches',
            ], true)) {
                return Http::response([
                    $path === '/api/v1/admin/training/datasets/releases' ? 'releases' : 'batches' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }
            if ($path === '/api/v1/admin/training/adapters/') {
                return Http::response(['adapters' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $this->get(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertOk()
            ->assertSee('Наборы')
            ->assertDontSee('Прогресс набора данных');
    }

    public function test_only_admin_can_open_coverage_page(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::User]));

        $this->get(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    private function expertPayload(): array
    {
        return [
            'key' => 'system_architect',
            'name' => 'Системный архитектор',
            'system_prompt' => 'Ты эксперт по системной архитектуре.',
            'keywords' => [],
            'sort_order' => 10,
            'is_enabled' => true,
            'is_system' => true,
        ];
    }

    private function statsPayload(): array
    {
        return [
            'expert' => 'system_architect',
            'approved_examples' => 3,
            'target' => 59,
            'topics' => [
                ['key' => 'api_gateway', 'target' => 5, 'count' => 1, 'missing' => 4, 'coverage' => 0.2],
                ['key' => 'caching', 'target' => 4, 'count' => 4, 'missing' => 0, 'coverage' => 1.0],
            ],
            'missing_topics' => ['api_gateway'],
        ];
    }
}
