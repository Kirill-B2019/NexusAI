<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Services\NexusApiClient;
use App\Services\NexusTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Контроллер задач проекта.
 * Обрабатывает создание, обновление и удаление задач.
 */
class TaskController extends Controller
{
    /**
     * Отображение списка задач проекта.
     *
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид со списком задач
     */
    public function index(string $nexusProjectId, NexusApiClient $nexus): View
    {
        try {
            /** @var list<\App\Services\NexusTask> $tasks */
            $tasks = $nexus->listTasks($nexusProjectId);
            $error = null;
        } catch (\App\Exceptions\NexusApiException $exception) {
            $tasks = [];
            $error = $exception->getMessage();
        }

        return view('pages.admin.tasks', [
            'nexusProjectId' => $nexusProjectId,
            'tasks' => $tasks,
            'error' => $error,
        ]);
    }

    /**
     * Создание новой задачи.
     *
     * @param  StoreTaskRequest  $request  Запрос с валидацией данных
     * @param  string  $nexusProjectId  UUID проекта в NEXUS AI
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом создания
     */
    public function store(StoreTaskRequest $request, string $nexusProjectId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->createTask(
                $nexusProjectId,
                $request->string('title')->toString(),
                $request->string('description')->toString(),
                $request->input('assignee_expert'),
                $request->input('due_date'),
            );

            return redirect()->route('admin.tasks', ['nexusProjectId' => $nexusProjectId])
                ->with('status', 'task_created');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['create' => $exception->getMessage()]);
        }
    }

    /**
     * Обновление существующей задачи.
     *
     * @param  UpdateTaskRequest  $request  Запрос с валидацией данных
     * @param  string  $taskId  UUID задачи
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом обновления
     */
    public function update(UpdateTaskRequest $request, string $taskId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $changes = [
                'title' => $request->string('title')->toString(),
                'description' => $request->string('description')->toString(),
            ];

            if ($request->filled('status')) {
                $changes['status'] = $request->input('status');
            }

            if ($request->exists('assignee_expert')) {
                $changes['assignee_expert'] = $request->input('assignee_expert');
            }

            if ($request->exists('due_date')) {
                $changes['due_date'] = $request->input('due_date');
            }

            $nexus->updateTask($taskId, $changes);

            return back()->with('status', 'task_updated');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['update' => $exception->getMessage()]);
        }
    }

    /**
     * Удаление задачи.
     *
     * @param  string  $taskId  UUID задачи
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return RedirectResponse Перенаправление со статусом удаления
     */
    public function destroy(string $taskId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->deleteTask($taskId);

            return back()->with('status', 'task_deleted');
        } catch (\App\Exceptions\NexusApiException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }
    }
}
