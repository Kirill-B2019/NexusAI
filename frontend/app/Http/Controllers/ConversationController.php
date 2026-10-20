<?php

namespace App\Http\Controllers;

use App\Services\NexusApiClient;
use App\Services\NexusConversation;
use App\Services\NexusConversationMessage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Контроллер истории разговоров.
 * Отображает список бесед проекта и просматривает сообщения в конкретной беседе.
 */
class ConversationController extends Controller
{
    /**
     * Отображение списка бесед проекта.
     *
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид со списком бесед
     */
    public function index(string $nexusProjectId, NexusApiClient $nexus): View
    {
        try {
            /** @var list<\App\Services\NexusConversation> $conversations */
            $conversations = $nexus->listConversations($nexusProjectId);
            $error = null;
        } catch (\App\Exceptions\NexusApiException $exception) {
            $conversations = [];
            $error = $exception->getMessage();
        }

        return view('pages.admin.conversations', [
            'nexusProjectId' => $nexusProjectId,
            'conversations' => $conversations,
            'error' => $error,
        ]);
    }

    /**
     * Отображение сообщений конкретной беседы.
     *
     * @param  string  $conversationId  UUID беседы
     * @param  Request  $request  HTTP-запрос
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид с сообщениями беседы
     */
    public function messages(string $conversationId, Request $request, NexusApiClient $nexus): View
    {
        try {
            $limit = $request->integer('limit', 50);
            $cursor = $request->string('cursor')->toString() ?: null;

            /** @var list<\App\Services\NexusConversationMessage> $messages */
            $messages = $nexus->listConversationMessages($conversationId, $limit, $cursor);
            $error = null;
        } catch (\App\Exceptions\NexusApiException $exception) {
            $messages = [];
            $error = $exception->getMessage();
        }

        return view('pages.admin.conversations', [
            'nexusProjectId' => $request->input('project_id'),
            'conversationId' => $conversationId,
            'messages' => $messages,
            'error' => $error,
            'showMessages' => true,
        ]);
    }
}
