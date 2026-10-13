<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\StreamChatRequest;
use App\Models\Project;
use App\Services\NexusApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatStreamController extends Controller
{
    public function __invoke(StreamChatRequest $request, NexusApiClient $nexus): JsonResponse|StreamedResponse
    {
        $project = Project::query()
            ->visibleTo($request->user())
            ->find($request->integer('project_id'));

        abort_if($project === null, 404);
        Gate::authorize('view', $project);

        try {
            $upstream = $nexus->streamChat($project, $request->string('message')->toString());
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

        return response()->stream(function () use ($upstream): void {
            @set_time_limit(0);
            $body = $upstream->toPsrResponse()->getBody();

            try {
                while (! $body->eof() && ! connection_aborted()) {
                    $chunk = $body->read(8192);

                    if ($chunk === '') {
                        continue;
                    }

                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } catch (Throwable $exception) {
                Log::warning('NEXUS AI SSE stream was interrupted.', [
                    'exception' => $exception::class,
                ]);

                if (! connection_aborted()) {
                    echo "event: error\ndata: {\"message\":\"Поток ответа NEXUS AI прерван.\"}\n\n";
                    flush();
                }
            } finally {
                $body->close();
            }
        }, $upstream->status(), [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
