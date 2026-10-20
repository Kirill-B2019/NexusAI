<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchKnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH_ID = '9d5876da-6ca4-49aa-8814-d4cf2466c8f1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_research_draft_is_proxied_and_shown_for_review(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://nexus.test/api/v1/admin/training/research/') {
                return Http::response($this->draftPayload());
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-research.draft'), [
                'expert' => 'system_architect',
                'query' => 'laravel caching',
                'count' => 5,
            ])
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHas('status', 'training_research_drafted')
            ->assertSessionHas('research_drafts');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/research/'
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && str_contains($request->body(), '"query":"laravel caching"')
            && str_contains($request->body(), '"count":5'));
    }

    public function test_research_draft_is_rendered_with_sources_and_editable_pairs(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/experts/all' => Http::response(['experts' => [$this->expertPayload()]]),
                '/api/v1/admin/training/datasets/batches' => Http::response([
                    'batches' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 0,
                ]),
                '/api/v1/admin/training/datasets/releases' => Http::response([
                    'releases' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 0,
                ]),
                '/api/v1/admin/training/adapters/' => Http::response([
                    'adapters' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 0,
                ]),
                default => Http::response(['detail' => 'Unexpected upstream request.'], 404),
            };
        });

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->get(route('admin.training-datasets'))
            ->assertOk()
            ->assertSee('Черновики из интернета')
            ->assertSee('Проверьте пары перед добавлением')
            ->assertSee('https://laravel.com/docs/cache', false)
            ->assertSee('Принять и включить в обучение')
            ->assertSee('Как работает кэш в Laravel?')
            ->assertSee('name="accepted[]"', false)
            ->assertSee('name="drafts[0][question]"', false)
            ->assertSee('name="drafts[0][answer]"', false)
            ->assertSee('name="drafts[0][source_url]"', false)
            ->assertSee(route('admin.training-research.cancel'), false);
    }

    public function test_research_draft_surfaces_unconfigured_search(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/research/' => Http::response([
                'detail' => 'Поиск не настроен: не задан BRAVE_SEARCH_API_KEY.',
            ], 503),
        ]);

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-research.draft'), [
                'expert' => 'system_architect',
                'query' => 'laravel caching',
                'count' => 5,
            ])
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHasErrors('research')
            ->assertSessionMissing('research_drafts');
    }

    public function test_research_draft_validates_input_locally(): void
    {
        Http::fake();

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-research.draft'), [
                'expert' => 'system_architect',
                'query' => 'xy',
                'count' => 0,
            ])
            ->assertSessionHasErrors(['query', 'count']);

        Http::assertNothingSent();
    }

    public function test_research_drafts_are_approved_and_released(): void
    {
        Http::fake($this->acceptFakes());

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    [
                        'question' => 'Как работает кэш в Laravel?',
                        'answer' => 'Кэш хранит результаты для повторного использования.',
                        'source_url' => 'https://laravel.com/docs/cache',
                    ],
                ],
                'accepted' => [0],
            ])
            ->assertRedirect(route('admin.training-datasets', [
                'batch' => self::BATCH_ID,
                'expert' => 'system_architect',
            ]))
            ->assertSessionHas('status', 'training_research_accepted')
            ->assertSessionHas('release_example_count', 1)
            ->assertSessionMissing('research_drafts');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches'
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && str_contains($request->body(), 'name="expert"')
            && str_contains($request->body(), 'system_architect')
            && str_contains($request->body(), 'filename="research-system_architect.jsonl"')
            && str_contains($request->body(), 'web_research')
            && str_contains($request->body(), 'question'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches/'.self::BATCH_ID.'/approve'
            && $request->data() === ['duplicates_reviewed' => true]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/releases'
            && $request->data() === ['expert' => 'system_architect']);
    }

    public function test_only_accepted_research_drafts_are_imported(): void
    {
        Http::fake($this->acceptFakes());

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    [
                        'question' => 'Первый вопрос?',
                        'answer' => 'Первый ответ.',
                    ],
                    [
                        'question' => 'Второй вопрос?',
                        'answer' => 'Второй ответ.',
                    ],
                ],
                'accepted' => [1],
            ])
            ->assertSessionHas('status', 'training_research_accepted');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches'
            && str_contains($request->body(), 'Второй вопрос?')
            && ! str_contains($request->body(), 'Первый вопрос?'));
    }

    public function test_research_accept_requires_at_least_one_selected_pair(): void
    {
        Http::fake();

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    ['question' => 'Вопрос?', 'answer' => 'Ответ.'],
                ],
                'accepted' => [],
            ])
            ->assertSessionHasErrors('accepted');

        Http::assertNothingSent();
    }

    public function test_research_accept_leaves_batch_for_manual_review_on_duplicates(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/batches' => Http::response([
                ...$this->batchSummaryPayload(duplicates: 1),
                'validation' => [
                    'total' => 1,
                    'valid' => 1,
                    'invalid' => 0,
                    'duplicates' => [['row' => 1, 'message' => 'Похожий вопрос.']],
                    'errors' => [],
                    'examples' => [],
                ],
            ], 201),
        ]);

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    ['question' => 'Вопрос?', 'answer' => 'Ответ.'],
                ],
                'accepted' => [0],
            ])
            ->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_research_saved_for_review')
            ->assertSessionMissing('research_drafts');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/approve'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/releases');
    }

    public function test_research_accept_surfaces_approval_failure(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/batches' => Http::response([
                ...$this->batchSummaryPayload(),
                'validation' => [
                    'total' => 1,
                    'valid' => 1,
                    'invalid' => 0,
                    'duplicates' => [],
                    'errors' => [],
                    'examples' => [],
                ],
            ], 201),
            'https://nexus.test/api/v1/admin/training/datasets/batches/'.self::BATCH_ID.'/approve' => Http::response([
                'detail' => 'Нельзя утвердить партию: исправьте или удалите некорректные строки.',
            ], 409),
        ]);

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    ['question' => 'Вопрос?', 'answer' => 'Ответ.'],
                ],
                'accepted' => [0],
            ])
            ->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHasErrors('research');

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/releases');
    }

    public function test_research_topic_label_is_stored_in_pair_metadata(): void
    {
        Http::fake($this->acceptFakes());

        $this->withSession(['research_drafts' => [...$this->draftPayload(), 'topic' => 'caching']])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    [
                        'question' => 'Как работает кэш в Laravel?',
                        'answer' => 'Кэш хранит результаты для повторного использования.',
                    ],
                ],
                'accepted' => [0],
            ])
            ->assertRedirect(route('admin.training-datasets', [
                'batch' => self::BATCH_ID,
                'expert' => 'system_architect',
            ]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches'
            && str_contains($request->body(), '"topic":"caching"'));
    }

    public function test_research_drafts_require_question_and_answer(): void
    {
        Http::fake();

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->post(route('admin.training-research.store'), [
                'expert' => 'system_architect',
                'drafts' => [
                    ['question' => '', 'answer' => 'Ответ без вопроса.'],
                ],
                'accepted' => [0],
            ])
            ->assertSessionHasErrors('drafts.0.question');

        Http::assertNothingSent();
    }

    public function test_research_cancel_clears_drafts(): void
    {
        Http::fake();

        $this->withSession(['research_drafts' => $this->draftPayload()])
            ->from(route('admin.training-datasets'))
            ->delete(route('admin.training-research.cancel'))
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHas('status', 'training_research_cancelled')
            ->assertSessionMissing('research_drafts');

        Http::assertNothingSent();
    }

    public function test_only_admin_can_use_research_and_store_drafts(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]));

        $this->post(route('admin.training-research.draft'), [
            'expert' => 'system_architect',
            'query' => 'laravel caching',
            'count' => 5,
        ])->assertForbidden();

        $this->post(route('admin.training-research.store'), [
            'expert' => 'system_architect',
            'drafts' => [
                ['question' => 'Вопрос', 'answer' => 'Ответ'],
            ],
            'accepted' => [0],
        ])->assertForbidden();

        $this->delete(route('admin.training-research.cancel'))->assertForbidden();

        Http::assertNothingSent();
    }

    private function draftPayload(): array
    {
        return [
            'expert' => 'system_architect',
            'query' => 'laravel caching',
            'sources' => [
                ['title' => 'Cache', 'url' => 'https://laravel.com/docs/cache'],
            ],
            'drafts' => [
                [
                    'question' => 'Как работает кэш в Laravel?',
                    'answer' => 'Кэш хранит результаты запросов, чтобы не выполнять их повторно.',
                    'source_url' => 'https://laravel.com/docs/cache',
                ],
            ],
        ];
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

    private function acceptFakes(): \Closure
    {
        return function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST'
                    && $path === '/api/v1/admin/training/datasets/batches' => Http::response([
                        ...$this->batchSummaryPayload(),
                        'validation' => [
                            'total' => 1,
                            'valid' => 1,
                            'invalid' => 0,
                            'duplicates' => [],
                            'errors' => [],
                            'examples' => [],
                        ],
                    ], 201),
                $request->method() === 'POST'
                    && $path === '/api/v1/admin/training/datasets/batches/'.self::BATCH_ID.'/approve' => Http::response(
                        $this->batchSummaryPayload(status: 'approved'),
                    ),
                $request->method() === 'POST'
                    && $path === '/api/v1/admin/training/datasets/releases' => Http::response([
                        'id' => self::BATCH_ID,
                        'expert' => 'system_architect',
                        'batch_ids' => [self::BATCH_ID],
                        'example_count' => 1,
                        'sha256' => str_repeat('b', 64),
                        'format_version' => 2,
                        'prep_params' => [],
                        'created_by' => 'admin',
                        'created_at' => '2026-10-18T17:05:00+00:00',
                    ], 201),
                default => Http::response(['detail' => 'Unexpected upstream request.'], 404),
            };
        };
    }

    private function batchSummaryPayload(
        string $status = 'pending_review',
        int $invalid = 0,
        int $duplicates = 0,
    ): array {
        return [
            'id' => self::BATCH_ID,
            'expert' => 'system_architect',
            'filename' => 'research-system_architect.jsonl',
            'sha256' => str_repeat('a', 64),
            'status' => $status,
            'total' => 1,
            'valid' => 1,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'created_by' => 'admin',
            'created_at' => '2026-10-18T17:00:00+00:00',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
