<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrainingDatasetAdminTest extends TestCase
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

    public function test_training_dataset_page_shows_experts_batches_and_manual_review_details(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/experts/all')) {
                return Http::response(['experts' => [$this->expertPayload()]]);
            }

            if (str_ends_with($request->url(), '/batches/'.$this::BATCH_ID)) {
                return Http::response($this->batchDetailPayload());
            }

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/admin/training/datasets/releases') {
                return Http::response([
                    'releases' => [$this->releasePayload()],
                    'total' => 1,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/admin/training/datasets/batches') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

                return Http::response([
                    'batches' => [$this->batchSummaryPayload()],
                    'total' => 101,
                    'limit' => 50,
                    'offset' => (int) ($query['offset'] ?? 0),
                ]);
            }

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/admin/training/adapters/') {
                return Http::response([
                    'adapters' => [$this->adapterPayload()],
                    'total' => 7,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $response = $this->get(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertOk()
            ->assertSee('Наборы')
            ->assertSee('Системный архитектор')
            ->assertSee('qa.jsonl')
            ->assertSee('Похожие вопросы — проверьте вручную')
            ->assertSee('Строка 2 похожа на строку 1')
            ->assertSee('name="duplicates_reviewed"', false)
            ->assertSee('Проверить дубль')
            ->assertSee('Утвердить snapshot')
            ->assertSee('Обучение выполняется на внешнем GPU')
            ->assertSee('Черновики из интернета')
            ->assertSee('Найти и составить черновики')
            ->assertSee('Снимки датасета эксперта')
            ->assertSee('Собрать release-снимок')
            ->assertSee('cccccccccccc', false)
            ->assertSee('1 снимков')
            ->assertSee('Реестр LoRA-адаптеров')
            ->assertSee('7 версий')
            ->assertSee('1–50 из 52')
            ->assertSee('example_offset=50')
            ->assertSee('Следующие партии')
            ->assertSee('offset=50')
            ->assertSee(route('admin.training-datasets.store'), false);
        $response->assertDontSee('admin-test-key');

        $this->get(route('admin.training-datasets', ['offset' => 50]))
            ->assertOk()
            ->assertSee('51–51 из 101')
            ->assertSee('Предыдущие партии')
            ->assertSee('offset=100');

        $this->get(route('admin.training-datasets', ['offset' => 100]))
            ->assertOk()
            ->assertSee('101–101 из 101')
            ->assertDontSee('Следующие партии');

        $this->get(route('admin.training-datasets', [
            'batch' => self::BATCH_ID,
            'example_offset' => 50,
        ]))->assertOk()
            ->assertSee('51–52 из 52')
            ->assertSee('Вопрос 52');

        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH)
            === '/api/v1/admin/training/datasets/batches'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_upload_is_proxied_server_side_and_redirects_to_the_saved_batch(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/batches')) {
                return Http::response([
                    ...$this->batchSummaryPayload(),
                    'validation' => [
                        'total' => 1,
                        'valid' => 1,
                        'invalid' => 0,
                        'duplicates' => [],
                        'errors' => [],
                        'examples' => [],
                    ],
                ], 201);
            }

            if (str_ends_with($request->url(), '/experts/all')) {
                return Http::response(['experts' => [$this->expertPayload()]]);
            }

            if (str_ends_with($request->url(), '/batches/'.self::BATCH_ID)) {
                return Http::response($this->batchDetailPayload());
            }

            if (str_ends_with($request->url(), '/batches')) {
                return Http::response([
                    'batches' => [$this->batchSummaryPayload()],
                    'total' => 1,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $this->post(route('admin.training-datasets.store'), [
            'expert' => 'system_architect',
            'file' => UploadedFile::fake()->createWithContent(
                'qa.jsonl',
                '{"messages":[{"role":"user","content":"Вопрос"},{"role":"assistant","content":"Ответ"}]}',
            ),
        ])->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_dataset_batch_created');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches'
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && str_contains($request->body(), 'name="expert"')
            && str_contains($request->body(), 'system_architect')
            && str_contains($request->body(), 'filename="qa.jsonl"'));
    }

    public function test_upload_of_duplicate_file_redirects_to_reused_batch_notice(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/batches' => Http::response([
                ...$this->batchSummaryPayload(),
                'reused' => true,
                'validation' => [
                    'total' => 1,
                    'valid' => 1,
                    'invalid' => 0,
                    'duplicates' => [],
                    'errors' => [],
                    'examples' => [],
                ],
            ], 201),
        ]);

        $this->post(route('admin.training-datasets.store'), [
            'expert' => 'system_architect',
            'file' => UploadedFile::fake()->createWithContent(
                'qa.jsonl',
                '{"question":"Вопрос","answer":"Ответ"}',
            ),
        ])->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_dataset_batch_reused');
    }

    public function test_release_creation_is_proxied_with_server_side_admin_key(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/releases' => Http::response([
                ...$this->releasePayload(),
                'example_count' => 120,
            ], 201),
        ]);

        $this->post(route('admin.training-datasets.releases.store'), [
            'expert' => 'system_architect',
        ])->assertRedirect(route('admin.training-datasets', ['expert' => 'system_architect']))
            ->assertSessionHas('status', 'training_dataset_release_created')
            ->assertSessionHas('release_example_count', 120);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/releases'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_example_edit_and_delete_are_proxied_with_server_side_key(): void
    {
        $exampleUrl = 'https://nexus.test/api/v1/admin/training/datasets/batches/'
            .self::BATCH_ID.'/examples/7';

        Http::fake([
            $exampleUrl => Http::response([
                ...$this->batchSummaryPayload(),
                'invalid' => 0,
                'validation' => ['examples' => []],
            ]),
        ]);

        $this->patch(route('admin.training-datasets.examples.update', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]), [
            'metadata' => json_encode(['expert' => 'system_architect'], JSON_UNESCAPED_UNICODE),
            'expert' => 'system_architect',
        ])->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_dataset_example_updated');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/examples/7')
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && str_contains($request->body(), 'system_architect'));

        $this->delete(route('admin.training-datasets.examples.destroy', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]))->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_dataset_example_deleted');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/examples/7')
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_example_edit_without_changes_is_rejected_locally(): void
    {
        Http::fake();

        $this->patch(route('admin.training-datasets.examples.update', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]), [])->assertSessionHasErrors('example');

        Http::assertNothingSent();
    }

    public function test_example_edit_conflict_is_surfaced_to_the_admin(): void
    {
        $exampleUrl = 'https://nexus.test/api/v1/admin/training/datasets/batches/'
            .self::BATCH_ID.'/examples/7';

        Http::fake([
            $exampleUrl => Http::response(['detail' => 'Изменять можно только партию со статусом «ожидает проверки».'], 409),
        ]);

        $this->patch(route('admin.training-datasets.examples.update', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]), ['question' => 'Новый вопрос', 'answer' => 'Новый ответ'])
            ->assertSessionHasErrors('example');
    }

    public function test_release_creation_conflict_is_surfaced_to_the_admin(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/releases' => Http::response([
                'detail' => 'Для эксперта нет утверждённых партий с snapshot.',
            ], 409),
        ]);

        $this->post(route('admin.training-datasets.releases.store'), [
            'expert' => 'system_architect',
        ])->assertSessionHasErrors('release');
    }

    public function test_approval_is_proxied_with_server_side_admin_key(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/admin/training/datasets/batches/'.self::BATCH_ID.'/approve' => Http::response([
                ...$this->batchSummaryPayload(),
                'status' => 'approved',
            ]),
        ]);

        $this->post(route('admin.training-datasets.approve', ['batchId' => self::BATCH_ID]), [
            'duplicates_reviewed' => '1',
        ])
            ->assertRedirect(route('admin.training-datasets', ['batch' => self::BATCH_ID]))
            ->assertSessionHas('status', 'training_dataset_batch_approved');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/admin/training/datasets/batches/'.self::BATCH_ID.'/approve'
            && $request->data()['duplicates_reviewed'] === true
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_only_admin_can_open_or_submit_training_dataset_batches(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]));

        $this->get(route('admin.training-datasets'))->assertForbidden();
        $this->post(route('admin.training-datasets.store'), [
            'expert' => 'system_architect',
            'file' => UploadedFile::fake()->createWithContent('qa.jsonl', '{}'),
        ])->assertForbidden();
        $this->post(route('admin.training-datasets.releases.store'), [
            'expert' => 'system_architect',
        ])->assertForbidden();
        $this->patch(route('admin.training-datasets.examples.update', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]), ['question' => 'Вопрос', 'answer' => 'Ответ'])->assertForbidden();
        $this->delete(route('admin.training-datasets.examples.destroy', [
            'batchId' => self::BATCH_ID,
            'row' => 7,
        ]))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_upload_rejects_unsupported_file_extensions_before_calling_nexus(): void
    {
        Http::fake();

        $this->from(route('admin.training-datasets'))
            ->post(route('admin.training-datasets.store'), [
                'expert' => 'system_architect',
                'file' => UploadedFile::fake()->createWithContent('qa.txt', 'not a dataset'),
            ])
            ->assertRedirect(route('admin.training-datasets'))
            ->assertSessionHasErrors('file');

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

    private function releasePayload(): array
    {
        return [
            'id' => '2f4f7d1c-1e3b-4a52-9c77-6a2f0c9a1b3d',
            'expert' => 'system_architect',
            'batch_ids' => [self::BATCH_ID],
            'example_count' => 52,
            'sha256' => str_repeat('c', 64),
            'format_version' => 2,
            'prep_params' => ['duplicate_threshold' => 0.75],
            'created_by' => 'admin',
            'created_at' => '2026-10-16T18:00:00+00:00',
        ];
    }

    private function adapterPayload(): array
    {
        return [
            'id' => '5e0f2f7a-9f5a-4c1e-8f19-2b7f4d6a0c11',
            'expert' => 'system_architect',
            'version' => 3,
            'status' => 'published',
            'filename' => 'system_architect-abc123.gguf',
            'sha256' => str_repeat('d', 64),
            'size_bytes' => 6291456,
            'base_model' => 'Qwen3-4B',
            'dataset_release_id' => '2f4f7d1c-1e3b-4a52-9c77-6a2f0c9a1b3d',
            'created_by' => 'admin',
            'created_at' => '2026-10-18T10:00:00+00:00',
            'published_by' => 'admin',
            'published_at' => '2026-10-18T11:00:00+00:00',
            'rolled_back_by' => null,
            'rolled_back_at' => null,
        ];
    }

    private function batchSummaryPayload(): array
    {
        return [
            'id' => self::BATCH_ID,
            'expert' => 'system_architect',
            'filename' => 'qa.jsonl',
            'sha256' => str_repeat('a', 64),
            'status' => 'pending_review',
            'total' => 52,
            'valid' => 52,
            'invalid' => 0,
            'duplicates' => 1,
            'created_by' => 'admin',
            'created_at' => '2026-10-16T17:00:00+00:00',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    private function batchDetailPayload(): array
    {
        return [
            ...$this->batchSummaryPayload(),
            'validation' => [
                'total' => 52,
                'valid' => 52,
                'invalid' => 0,
                'duplicates' => [[
                    'row' => 2,
                    'matches_batch_id' => '8cf81d9e-b111-41f5-b267-4a4bbb5b7ace',
                    'matches_row' => 1,
                    'matches_question' => 'Вопрос один',
                    'similarity' => 1.0,
                ]],
                'errors' => [],
                'examples' => array_map(
                    static fn (int $row): array => [
                        'row' => $row,
                        'question' => $row === 1 ? 'Вопрос один' : "Вопрос {$row}",
                        'answer' => $row === 1 ? 'Ответ один' : "Ответ {$row}",
                        'metadata' => [],
                        'valid' => true,
                        'status' => $row === 2 ? 'duplicate_review' : 'ready',
                    ],
                    range(1, 52),
                ),
            ],
        ];
    }
}
