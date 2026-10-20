<?php

namespace App\Http\Controllers;

use App\Services\NexusApiClient;
use App\Services\NexusAuditEntry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Контроллер журнала аудита.
 * Отображает лог действий с фильтрацией по исполнителю и типу сущности.
 */
class AuditController extends Controller
{
    /**
     * Отображение журнала аудита с фильтрами.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     * @param  NexusApiClient  $nexus  Клиент для вызовов NEXUS API
     * @return View Вид с таблицей записей аудита
     */
    public function index(Request $request, NexusApiClient $nexus): View
    {
        try {
            $actor = $request->string('actor')->toString() ?: null;
            $entityType = $request->string('entity_type')->toString() ?: null;
            $limit = $request->integer('limit', 50);

            /** @var list<\App\Services\NexusAuditEntry> $entries */
            $entries = $nexus->listAudit($actor, $entityType, $limit);
            $error = null;
        } catch (\App\Exceptions\NexusApiException $exception) {
            $entries = [];
            $error = $exception->getMessage();
        }

        return view('pages.admin.audit', [
            'entries' => $entries,
            'error' => $error,
            'actor' => $actor,
            'entityType' => $entityType,
            'limit' => $limit,
        ]);
    }
}
