<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\StreamChatRequest;
use App\Http\Responses\ProxySseResponse;
use App\Models\Project;
use App\Services\NexusApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController extends Controller
{
    public function __invoke(StreamChatRequest $request, NexusApiClient $nexus): JsonResponse|StreamedResponse
    {
        $project = Project::query()
            ->visibleTo($request->user())
            ->find($request->integer('project_id'));

        abort_if($project === null, 404);
        Gate::authorize('view', $project);

        // Формируем тело запроса к NEXUS AI
        $body = [
            'message' => $request->string('message')->toString(),
            'project_id' => $project->nexus_project_id,
            'use_rag' => $request->boolean('use_rag', true),
        ];

        // Добавляем параметры выбора экспертов
        $expertMode = $request->input('expert_mode');
        if ($expertMode === 'single') {
            $body['expert'] = $request->string('expert')->toString();
        } elseif ($expertMode === 'manual') {
            $body['experts'] = $request->input('experts', []);
        }
        // auto — ничего не добавляем, сервер сам выбирает

        // Thinking-режим
        $body['thinking'] = $request->boolean('thinking', false);

        // LLM-агрегатор
        $body['use_llm_aggregator'] = $request->boolean('use_llm_aggregator', false);

        // Conversation ID для продолжения диалога
        $conversationId = $request->string('conversation_id')->toString();
        if ($conversationId !== '') {
            $body['conversation_id'] = $conversationId;
            $body['save_to_conversation'] = $request->boolean('save_to_conversation', true);
        }

        try {
            $upstream = $nexus->streamChat($project, $body);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI chat request could not be started.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return response()->json([
                'message' => $exception->configurationError
                    ? 'Подключение NEXUS AI не настроено.'
                    : 'Не удалось подключиться к NEXUS AI.',
            ], $exception->configurationError ? 503 : 502);
        }

        if (! $upstream->successful()) {
            return response()->json([
                'message' => match ($upstream->status()) {
                    401, 403 => 'NEXUS AI отклонил project API key для выбранного проекта.',
                    429 => 'Слишком много запросов к NEXUS AI. Попробуйте позже.',
                    default => 'NEXUS AI не смог начать поток ответа.',
                },
            ], $upstream->status() >= 400 ? $upstream->status() : 502);
        }

        return ProxySseResponse::fromUpstream($upstream);
    }
}
