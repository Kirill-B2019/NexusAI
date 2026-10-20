<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\AnalyzeDocumentsRequest;
use App\Http\Requests\AskDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Responses\ProxySseResponse;
use App\Services\NexusApiClient;
use App\Services\NexusDocument;
use App\Services\NexusExpert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контроллер управления документами RAG.
 * Обрабатывает загрузку, удаление, переиндексацию и запросы по документам.
 */
class DocumentController extends Controller
{
    /**
     * Отображение списка документов проекта.
     *
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид с таблицей документов и сообщениями об ошибках
     */
    public function index(Request $request, string $nexusProjectId, NexusApiClient $nexus): View|JsonResponse
    {
        $projectName = null;
        $experts = [];
        $expertCatalogError = false;
        $expectsJson = $request->expectsJson();

        if (! $expectsJson) {
            try {
                $project = $nexus->getProject($nexusProjectId);
                $projectName = $project->name;
            } catch (\Throwable $exception) {
                Log::warning('Failed to get project name for documents page.', [
                    'project_id' => $nexusProjectId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            /** @var list<NexusDocument> $documents */
            $documents = $nexus->listDocuments($nexusProjectId);
            $error = null;
        } catch (NexusApiException $exception) {
            $documents = [];

            // Улучшаем сообщение об ошибке
            if ($exception->getMessage() === 'NEXUS AI returned an unexpected experts response.') {
                $error = 'Ошибка при загрузке документов: NEXUS AI вернул ответ в неожиданном формате. Проверьте, что проект существует и документы доступны.';
            } else {
                $error = $exception->getMessage();
            }
        }

        if (! $expectsJson && $error === null) {
            try {
                $experts = array_values(array_filter(
                    $nexus->listExperts(),
                    static fn (NexusExpert $expert): bool => $expert->enabled,
                ));
            } catch (NexusApiException $exception) {
                Log::warning('Failed to load experts for the document question dialog.', [
                    'status' => $exception->statusCode,
                ]);
                $expertCatalogError = true;
            }
        }

        if ($expectsJson) {
            if ($error !== null) {
                return response()->json(['error' => $error], 502);
            }

            return response()->json([
                'documents' => array_map(
                    static fn (NexusDocument $document): array => [
                        'id' => $document->id,
                        'name' => $document->name,
                        'status' => $document->status,
                        'error' => $document->error,
                    ],
                    $documents,
                ),
            ]);
        }

        return view('pages.admin.documents', [
            'nexusProjectId' => $nexusProjectId,
            'projectName' => $projectName,
            'documents' => $documents,
            'error' => $error,
            'experts' => $experts,
            'expertCatalogError' => $expertCatalogError,
        ]);
    }

    /**
     * Загрузка нового документа в проект.
     *
     * @param  StoreDocumentRequest  $request  Запрос с валидацией файла
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом загрузки
     */
    public function upload(StoreDocumentRequest $request, string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $file = $request->file('file');
            $document = $nexus->uploadDocument(
                $nexusProjectId,
                $file->getPathname(),
                $file->getClientOriginalName(),
            );

            return redirect()->route('admin.documents', ['nexusProjectId' => $nexusProjectId])
                ->with('status', 'document_uploaded')
                ->with('document_name', $document->name);
        } catch (NexusApiException $exception) {
            return back()->withErrors(['upload' => $exception->getMessage()]);
        }
    }

    public function status(string $documentId, NexusApiClient $nexus): JsonResponse
    {
        try {
            return response()->json($nexus->getDocumentStatus($documentId));
        } catch (NexusApiException $exception) {
            return response()->json(
                ['error' => $exception->getMessage()],
                $exception->statusCode ?? 502,
            );
        }
    }

    /**
     * Переиндексация документа.
     *
     * @param  string  $documentId  UUID документа
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом переиндексации
     */
    public function reindex(string $documentId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->reindexDocument($documentId);

            return back()->with('status', 'document_reindexing');
        } catch (NexusApiException $exception) {
            return back()->withErrors(['reindex' => $exception->getMessage()]);
        }
    }

    /**
     * AJAX-запрос: задать вопрос по документу.
     *
     * @param  AskDocumentRequest  $request  Запрос с вопросом
     * @param  string  $documentId  UUID документа
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return JsonResponse JSON с ответом и источниками RAG
     */
    public function ask(AskDocumentRequest $request, string $documentId, NexusApiClient $nexus): JsonResponse
    {
        try {
            $result = $nexus->askDocument(
                $documentId,
                $request->string('question')->toString(),
                $request->input('expert'),
                $request->boolean('thinking'),
                $request->integer('rag_top_k', 6),
                (float) $request->input('rag_min_score', 0.4),
            );

            return response()->json([
                'answer' => $result['answer'] ?? $result['content'] ?? $result['message'] ?? 'Ответ не получен.',
                'sources' => is_array($result['sources'] ?? null) ? $result['sources'] : [],
                'rag_used' => (bool) ($result['rag_used'] ?? false),
                'expert' => is_string($result['expert'] ?? null) ? $result['expert'] : null,
            ]);
        } catch (NexusApiException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], $exception->statusCode ?? 500);
        }
    }

    public function askStream(
        AskDocumentRequest $request,
        string $nexusProjectId,
        string $documentId,
        NexusApiClient $nexus,
    ): JsonResponse|StreamedResponse {
        $body = [
            'message' => $request->string('question')->toString(),
            'project_id' => $nexusProjectId,
            'use_rag' => true,
            'document_ids' => [$documentId],
            'thinking' => $request->boolean('thinking'),
            'rag_top_k' => $request->integer('rag_top_k', 6),
            'rag_min_score' => (float) $request->input('rag_min_score', 0.4),
        ];
        $expert = $request->input('expert');
        if (is_string($expert) && $expert !== '') {
            $body['expert'] = $expert;
        }

        return $this->streamQuestion($body, $nexus);
    }

    public function analyzeStream(
        AnalyzeDocumentsRequest $request,
        string $nexusProjectId,
        NexusApiClient $nexus,
    ): JsonResponse|StreamedResponse {
        $documentIds = $request->validated('document_ids');

        try {
            foreach ($documentIds as $documentId) {
                $document = $nexus->getDocument($documentId);
                if ($document->projectId !== $nexusProjectId || ! $document->isReady()) {
                    return response()->json([
                        'message' => 'Выберите только готовые документы этого проекта.',
                    ], 422);
                }
            }
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not validate selected documents for RAG analysis.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return response()->json([
                'message' => $exception->statusCode === 404
                    ? 'Один или несколько выбранных документов недоступны для этого проекта.'
                    : 'Не удалось проверить выбранные документы в NEXUS AI.',
            ], $exception->statusCode === 404 ? 422 : 502);
        }

        $body = [
            'message' => $request->string('question')->toString(),
            'project_id' => $nexusProjectId,
            'use_rag' => true,
            'document_ids' => $documentIds,
            'thinking' => $request->boolean('thinking'),
            'rag_top_k' => $request->integer('rag_top_k', 6),
            'rag_min_score' => (float) $request->input('rag_min_score', 0.4),
        ];
        $expert = $request->input('expert');
        if (is_string($expert) && $expert !== '') {
            $body['expert'] = $expert;
        }

        return $this->streamQuestion($body, $nexus);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function streamQuestion(array $body, NexusApiClient $nexus): JsonResponse|StreamedResponse
    {
        try {
            $upstream = $nexus->streamDocumentQuestion($body);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI document question stream could not be started.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return response()->json([
                'message' => $exception->configurationError
                    ? 'Подключение NEXUS AI не настроено.'
                    : 'Не удалось подключиться к NEXUS AI для потокового ответа.',
            ], $exception->configurationError ? 503 : 502);
        }

        if (! $upstream->successful()) {
            return response()->json([
                'message' => match ($upstream->status()) {
                    401, 403 => 'NEXUS AI отклонил серверный admin API key.',
                    429 => 'Слишком много запросов к NEXUS AI. Попробуйте позже.',
                    default => 'NEXUS AI не смог начать поток ответа по документу.',
                },
            ], $upstream->status() >= 400 ? $upstream->status() : 502);
        }

        return ProxySseResponse::fromUpstream($upstream);
    }

    /**
     * Удаление документа.
     *
     * @param  string  $documentId  UUID документа
     * @param  Request  $request  HTTP-запрос
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом удаления
     */
    public function delete(string $documentId, Request $request, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->deleteDocument($documentId);

            return back()->with('status', 'document_deleted');
        } catch (NexusApiException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }
    }
}
