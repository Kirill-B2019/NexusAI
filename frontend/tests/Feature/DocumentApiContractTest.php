<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\NexusApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '2dc6c62d-6baf-4edf-9ecb-501d22d58d61';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nexus_ai.url' => 'https://nexus.test/api',
            'services.nexus_ai.admin_key' => 'admin-test-key',
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_document_page_reads_the_fastapi_envelope_and_polls_as_json(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/experts/all')) {
                return Http::response(['experts' => [[
                    'key' => 'system_architect',
                    'name' => 'Системный архитектор',
                    'system_prompt' => 'Ты системный архитектор программных систем.',
                    'sort_order' => 10,
                    'icon' => '🏗',
                    'is_enabled' => true,
                    'is_system' => true,
                ]]]);
            }

            if (str_ends_with($request->url(), '/documents')) {
                return Http::response([
                    'documents' => [[
                        'id' => 'document-1',
                        'project_id' => self::PROJECT_ID,
                        'filename' => 'safe-report.pdf',
                        'original_filename' => 'Report.pdf',
                        'file_size' => 1024,
                        'mime_type' => 'application/pdf',
                        'status' => 'processing',
                        'chunks_count' => 0,
                        'error' => null,
                        'created_at' => '2026-10-16T09:00:00+00:00',
                        'processed_at' => null,
                    ]],
                    'total' => 1,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }

            return Http::response(['detail' => 'Project lookup is not needed by this test.'], 404);
        });

        $this->get(route('admin.documents', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertOk()
            ->assertSee('Report.pdf')
            ->assertSee('Системный архитектор')
            ->assertSee('Автоматический выбор')
            ->assertSee('document-table', false)
            ->assertSee('ФРАГМЕНТЫ')
            ->assertSee('data-status="processing"', false)
            ->assertSee('data-document-chunks', false)
            ->assertSee('data-filter-processing', false)
            ->assertSee('data-status-count="processing"', false)
            ->assertSee('data-analyze-selected', false)
            ->assertSee("addEventListener('click', () => window.openAnalyzeModal())", false)
            ->assertSee('id="askDocModal"', false)
            ->assertSee('class="modal-dialog ask-document-dialog"', false)
            ->assertSee('class="project-form ask-document-form"', false)
            ->assertSee('Enter — отправить · Ctrl+Enter — новая строка')
            ->assertSee('window.openAskModal = function(docId, docName)', false)
            ->assertSee('data-document-select', false)
            ->assertSee('1,0 КБ')
            ->assertSee(route('admin.documents.ask-stream', [
                'nexusProjectId' => self::PROJECT_ID,
                'documentId' => '__DOCUMENT_ID__',
            ]), false)
            ->assertSee('50 МБ')
            ->assertSee('disabled', false)
            ->assertSee(route('admin.conversations', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertSee(route('admin.decisions', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertSee(route('admin.tasks', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertDontSee('Сравнение документов')
            ->assertDontSee('name="name"', false);

        $this->get(route('admin.documents', ['nexusProjectId' => self::PROJECT_ID]), [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('documents.0.id', 'document-1')
            ->assertJsonPath('documents.0.status', 'processing');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://nexus.test/api/v1/projects/'.self::PROJECT_ID.'/documents'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
        Http::assertSentCount(4);
    }

    public function test_document_question_uses_the_fastapi_message_field_and_returns_empty_context_message(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/documents/document-1/ask' => Http::response([
                'rag_used' => false,
                'message' => 'В документе не найдены подходящие фрагменты.',
                'content' => null,
                'sources' => [],
                'expert' => 'system_architect',
            ]),
        ]);

        $this->postJson(route('admin.documents.ask', ['documentId' => 'document-1']), [
            'question' => 'Каков срок договора?',
            'expert' => 'system_architect',
            'thinking' => true,
            'rag_top_k' => 8,
            'rag_min_score' => 0.65,
        ])->assertOk()
            ->assertJsonPath('answer', 'В документе не найдены подходящие фрагменты.')
            ->assertJsonPath('sources', [])
            ->assertJsonPath('expert', 'system_architect')
            ->assertJsonPath('rag_used', false);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/documents/document-1/ask'
            && $request->data()['message'] === 'Каков срок договора?'
            && $request->data()['expert'] === 'system_architect'
            && $request->data()['thinking'] === true
            && $request->data()['rag_top_k'] === 8
            && $request->data()['rag_min_score'] === 0.65);
    }

    public function test_document_question_stream_uses_sse_and_limits_rag_to_the_selected_document(): void
    {
        $stream = "event: start\ndata: {\"experts_used\":[\"system_architect\"],\"rag_used\":true,\"sources\":[]}\n\n"
            ."event: expert_start\ndata: {\"expert\":\"system_architect\"}\n\n"
            ."event: token\ndata: {\"expert\":\"system_architect\",\"delta\":\"Ответ\"}\n\n"
            ."event: done\ndata: {\"summary\":{\"total\":1,\"ok\":1,\"failed\":0}}\n\n";

        Http::fake([
            'https://nexus.test/api/v1/chat/stream' => Http::response($stream, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $response = $this->postJson(route('admin.documents.ask-stream', [
            'nexusProjectId' => self::PROJECT_ID,
            'documentId' => '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
        ]), [
            'question' => 'Кратко изложи документ',
            'expert' => 'system_architect',
            'thinking' => true,
        ])->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        $this->assertSame($stream, $response->streamedContent());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/chat/stream'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && $request->data()['project_id'] === self::PROJECT_ID
            && $request->data()['document_ids'] === ['81d7a413-2a59-48aa-9e3f-1cc00f2ab32a']
            && $request->data()['use_rag'] === true
            && $request->data()['expert'] === 'system_architect'
            && $request->data()['thinking'] === true);
    }

    public function test_document_question_stream_is_admin_only(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]))
            ->postJson(route('admin.documents.ask-stream', [
                'nexusProjectId' => self::PROJECT_ID,
                'documentId' => '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
            ]), ['question' => 'Кратко изложи документ'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_multi_document_analysis_validates_project_documents_and_streams_rag_response(): void
    {
        $documentIds = [
            '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
            'e62c4ba7-c123-485a-9fd0-6bd41a862b11',
        ];
        $stream = "event: start\ndata: {\"rag_used\":true,\"sources\":[{\"document_name\":\"Report.pdf\"}]}\n\n"
            ."event: token\ndata: {\"expert\":\"system_architect\",\"delta\":\"Общий вывод\"}\n\n"
            ."event: done\ndata: {\"summary\":{\"total\":1,\"ok\":1,\"failed\":0}}\n\n";

        Http::fake(function (Request $request) use ($documentIds, $stream) {
            foreach ($documentIds as $documentId) {
                if ($request->url() === 'https://nexus.test/api/v1/documents/'.$documentId) {
                    return Http::response([
                        'id' => $documentId,
                        'project_id' => self::PROJECT_ID,
                        'original_filename' => 'Report.pdf',
                        'status' => 'ready',
                        'chunks_count' => 4,
                    ]);
                }
            }

            if ($request->url() === 'https://nexus.test/api/v1/chat/stream') {
                return Http::response($stream, 200, ['Content-Type' => 'text/event-stream']);
            }

            return Http::response(['detail' => 'Unexpected upstream request.'], 404);
        });

        $response = $this->postJson(route('admin.documents.analyze-stream', [
            'nexusProjectId' => self::PROJECT_ID,
        ]), [
            'question' => 'Какие общие выводы есть в документах?',
            'document_ids' => $documentIds,
            'expert' => 'system_architect',
            'thinking' => true,
        ])->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $this->assertSame($stream, $response->streamedContent());
        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://nexus.test/api/v1/chat/stream'
            && $request->hasHeader('X-API-Key', 'admin-test-key')
            && $request->data()['project_id'] === self::PROJECT_ID
            && $request->data()['document_ids'] === $documentIds
            && $request->data()['use_rag'] === true
            && $request->data()['expert'] === 'system_architect'
            && $request->data()['thinking'] === true);
        Http::assertSentCount(3);
    }

    public function test_multi_document_analysis_rejects_documents_outside_project_before_streaming(): void
    {
        $documentId = '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a';
        Http::fake([
            'https://nexus.test/api/v1/documents/'.$documentId => Http::response([
                'id' => $documentId,
                'project_id' => 'e62c4ba7-c123-485a-9fd0-6bd41a862b11',
                'original_filename' => 'Foreign.pdf',
                'status' => 'ready',
                'chunks_count' => 4,
            ]),
            'https://nexus.test/api/v1/chat/stream' => Http::response('unexpected'),
        ]);

        $this->postJson(route('admin.documents.analyze-stream', [
            'nexusProjectId' => self::PROJECT_ID,
        ]), [
            'question' => 'Сравни документы.',
            'document_ids' => [$documentId],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Выберите только готовые документы этого проекта.');

        Http::assertSentCount(1);
    }

    public function test_multi_document_analysis_is_admin_only(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]))
            ->postJson(route('admin.documents.analyze-stream', [
                'nexusProjectId' => self::PROJECT_ID,
            ]), [
                'question' => 'Сравни документы.',
                'document_ids' => ['81d7a413-2a59-48aa-9e3f-1cc00f2ab32a'],
            ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_document_question_stream_reports_upstream_errors_as_json(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/chat/stream' => Http::response([
                'detail' => 'Internal service detail must not be exposed.',
            ], 503),
        ]);

        $this->postJson(route('admin.documents.ask-stream', [
            'nexusProjectId' => self::PROJECT_ID,
            'documentId' => '81d7a413-2a59-48aa-9e3f-1cc00f2ab32a',
        ]), ['question' => 'Кратко изложи документ'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'NEXUS AI не смог начать поток ответа по документу.')
            ->assertDontSee('Internal service detail must not be exposed.');
    }

    public function test_document_status_endpoint_proxies_the_actual_fastapi_status_contract(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/documents/document-1/status' => Http::response([
                'id' => 'document-1',
                'status' => 'ready',
                'chunks_count' => 8,
                'error' => null,
                'processed_at' => '2026-10-16T09:01:00+00:00',
            ]),
        ]);

        $this->getJson(route('admin.documents.status', ['documentId' => 'document-1']))
            ->assertOk()
            ->assertJsonPath('id', 'document-1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('chunks_count', 8)
            ->assertJsonPath('error', null);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://nexus.test/api/v1/documents/document-1/status'
            && $request->hasHeader('X-API-Key', 'admin-test-key'));
    }

    public function test_document_status_endpoint_is_not_available_to_regular_users(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::User]));

        $this->getJson(route('admin.documents.status', ['documentId' => 'document-1']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_pending_documents_keep_their_distinct_filter_state(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/documents')) {
                return Http::response([
                    'documents' => [[
                        'id' => 'document-pending',
                        'project_id' => self::PROJECT_ID,
                        'filename' => 'pending.txt',
                        'original_filename' => 'pending.txt',
                        'status' => 'pending',
                        'chunks_count' => 0,
                        'created_at' => '2026-10-16T09:00:00+00:00',
                    ]],
                    'total' => 1,
                    'limit' => 50,
                    'offset' => 0,
                ]);
            }

            return Http::response(['detail' => 'Not required by this test.'], 404);
        });

        $this->get(route('admin.documents', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertOk()
            ->assertSee('data-status="pending"', false)
            ->assertSee('.document-item[data-api-status="pending"]', false)
            ->assertSee('setInterval(pollDocumentStatuses, 5000)', false);
    }

    public function test_document_status_endpoint_rejects_a_malformed_api_response(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/documents/document-1/status' => Http::response([
                'id' => 'document-1',
                'status' => 'unknown',
                'chunks_count' => 0,
                'error' => null,
                'processed_at' => null,
            ]),
        ]);

        $this->getJson(route('admin.documents.status', ['documentId' => 'document-1']))
            ->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_reindex_accepts_the_fastapi_status_response_without_document_metadata(): void
    {
        Http::fake([
            'https://nexus.test/api/v1/documents/document-1/reindex' => Http::response([
                'id' => 'document-1',
                'status' => 'pending',
                'message' => 'Переиндексация запущена',
            ]),
        ]);

        $this->from(route('admin.documents', ['nexusProjectId' => self::PROJECT_ID]))
            ->post(route('admin.documents.reindex', ['documentId' => 'document-1']))
            ->assertRedirect(route('admin.documents', ['nexusProjectId' => self::PROJECT_ID]))
            ->assertSessionHas('status', 'document_reindexing');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://nexus.test/api/v1/documents/document-1/reindex');
    }

    public function test_upload_uses_the_original_filename_instead_of_the_temporary_path(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'nexus-document-');
        file_put_contents($filePath, 'test document');

        try {
            Http::fake([
                'https://nexus.test/api/v1/projects/'.self::PROJECT_ID.'/documents' => Http::response([
                    'id' => 'document-1',
                    'project_id' => self::PROJECT_ID,
                    'filename' => 'report.pdf',
                    'original_filename' => 'report.pdf',
                    'status' => 'pending',
                    'chunks_count' => 0,
                    'created_at' => '2026-10-16T09:00:00+00:00',
                ], 201),
            ]);

            $document = app(NexusApiClient::class)->uploadDocument(
                self::PROJECT_ID,
                $filePath,
                'report.pdf',
            );

            $this->assertSame('report.pdf', $document->name);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->body(), 'filename="report.pdf"')
                && ! str_contains($request->body(), 'nexus-document-'));
        } finally {
            @unlink($filePath);
        }
    }
}
