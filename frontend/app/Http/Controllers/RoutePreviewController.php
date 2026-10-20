<?php

namespace App\Http\Controllers;

use App\Http\Requests\StreamChatRequest;
use App\Models\Project;
use App\Services\NexusApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class RoutePreviewController extends Controller
{
    public function __invoke(StreamChatRequest $request, NexusApiClient $nexus): JsonResponse
    {
        $project = Project::query()
            ->visibleTo($request->user())
            ->find($request->integer('project_id'));

        abort_if($project === null, 404);
        Gate::authorize('view', $project);

        // Формируем запрос к NEXUS AI /v1/chat/route
        $body = [
            'message' => $request->string('message')->toString(),
            'project_id' => $project->nexus_project_id,
        ];

        // Thinking-режим
        $body['thinking'] = $request->boolean('thinking', false);

        try {
            $response = $nexus->previewRoute($project, $body);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI route preview failed.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return response()->json([
                'message' => $exception->configurationError
                    ? 'Подключение NEXUS AI не настроено.'
                    : 'Не удалось подключиться к NEXUS AI.',
            ], $exception->configurationError ? 503 : 502);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => match ($response->status()) {
                    401, 403 => 'NEXUS AI отклонил project API key для выбранного проекта.',
                    default => 'NEXUS AI не смог предсказать маршрутизацию.',
                },
            ], $response->status() >= 400 ? $response->status() : 502);
        }

        $data = $response->json();

        return response()->json([
            'route' => $data['route'] ?? null,
            'experts' => $data['experts'] ?? [],
            'confidence' => $data['confidence'] ?? null,
            'reasoning' => $data['reasoning'] ?? null,
        ]);
    }
}
