<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDecisionRequest;
use App\Http\Requests\UpdateDecisionRequest;
use App\Services\NexusApiClient;
use App\Services\NexusDecision;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Контроллер решений проекта.
 * Обрабатывает создание, обновление и удаление решений.
 */
class DecisionController extends Controller
{
    /**
     * Отображение списка решений проекта.
     *
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид со списком решений
     */
    public function index(string $nexusProjectId, NexusApiClient $nexus): View
    {
        try {
            /** @var list<\App\Services\NexusDecision> $decisions */
            $decisions = $nexus->listDecisions($nexusProjectId);
            $error = null;
        } catch (\App\Exceptions\NexusApiException $exception) {
            $decisions = [];
            $error = $exception->getMessage();
        }

        return view('pages.admin.decisions', [
            'nexusProjectId' => $nexusProjectId,
            'decisions' => $decisions,
            'error' => $error,
        ]);
    }

    /**
     * Создание нового решения.
     *
     * @param  StoreDecisionRequest  $request  Запрос с валидацией данных
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом создания
     */
    public function store(StoreDecisionRequest $request, string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->createDecision(
                $nexusProjectId,
                $request->string('title')->toString(),
                $request->string('content')->toString(),
            );

            return redirect()->route('admin.decisions', ['nexusProjectId' => $nexusProjectId])
                ->with('status', 'decision_created');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['create' => $exception->getMessage()]);
        }
    }

    /**
     * Обновление существующего решения.
     *
     * @param  UpdateDecisionRequest  $request  Запрос с валидацией данных
     * @param  string  $decisionId  UUID решения
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом обновления
     */
    public function update(UpdateDecisionRequest $request, string $decisionId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $changes = [
                'title' => $request->string('title')->toString(),
                'content' => $request->string('content')->toString(),
            ];

            if ($request->filled('status')) {
                $changes['status'] = $request->input('status');
            }

            $nexus->updateDecision($decisionId, $changes);

            return back()->with('status', 'decision_updated');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['update' => $exception->getMessage()]);
        }
    }

    /**
     * Удаление решения.
     *
     * @param  string  $decisionId  UUID решения
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом удаления
     */
    public function destroy(string $decisionId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->deleteDecision($decisionId);

            return back()->with('status', 'decision_deleted');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }
    }
}
