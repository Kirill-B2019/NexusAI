<?php

use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpertController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoraAdapterController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoutePreviewController;
use App\Http\Controllers\SessionActivityController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TrainingDatasetController;
use App\Http\Controllers\TrainingResearchController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->middleware('idle.timeout')->name('home');

Route::post('/session/activity', [SessionActivityController::class, 'update'])
    ->middleware(['auth', 'throttle:120,1', 'idle.timeout'])
    ->name('session.activity.update');

Route::middleware(['auth', 'idle.timeout'])->group(function () {
    Route::post('/session/expire', [SessionActivityController::class, 'expire'])
        ->name('session.expire');

    Route::middleware('password.change')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->middleware('can:view-projects')
            ->name('projects');
        Route::get('/chat', [ProjectController::class, 'chat'])
            ->middleware('can:view-projects')
            ->name('chat');
        Route::post('/chat/stream', ChatStreamController::class)
            ->middleware(['can:manage-projects', 'throttle:20,1'])
            ->name('chat.stream');
        Route::post('/chat/route-preview', RoutePreviewController::class)
            ->middleware(['can:manage-projects', 'throttle:30,1'])
            ->name('chat.route-preview');

        Route::get('/admin/projects', [AdminProjectController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.projects');
        Route::get('/admin/projects/{nexusProjectId}', [AdminProjectController::class, 'show'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.show');
        Route::post('/admin/projects', [AdminProjectController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.projects.store');
        Route::patch('/admin/projects/{nexusProjectId}', [AdminProjectController::class, 'update'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.update');
        Route::post('/admin/projects/{nexusProjectId}/link', [AdminProjectController::class, 'link'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.link');
        Route::put('/admin/projects/{nexusProjectId}/assignment', [AdminProjectController::class, 'assign'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.assignment');
        Route::post('/admin/projects/{nexusProjectId}/keys/rotate', [AdminProjectController::class, 'rotateKey'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.keys.rotate');
        Route::post('/admin/projects/{nexusProjectId}/keys/retry-revoke', [AdminProjectController::class, 'retryPendingRevoke'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.keys.retry-revoke');
        Route::delete('/admin/projects/{nexusProjectId}/keys', [AdminProjectController::class, 'revokeKey'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.keys.revoke');
        Route::delete('/admin/projects/{nexusProjectId}/application-keys/{keyId}', [AdminProjectController::class, 'revokeExternalKey'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->whereUuid('keyId')
            ->name('admin.projects.application-keys.revoke');
        Route::post('/admin/projects/{nexusProjectId}/application-keys', [AdminProjectController::class, 'storeExternalKey'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:10,1'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.application-keys.store');
        Route::delete('/admin/projects/{nexusProjectId}', [AdminProjectController::class, 'destroy'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.projects.destroy');

        Route::get('/admin/experts', [ExpertController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.experts');

        Route::get('/admin/training/datasets', [TrainingDatasetController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.training-datasets');
        Route::post('/admin/training/datasets', [TrainingDatasetController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:10,1'])
            ->name('admin.training-datasets.store');
        Route::post('/admin/training/datasets/{batchId}/approve', [TrainingDatasetController::class, 'approve'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('batchId')
            ->name('admin.training-datasets.approve');
        Route::post('/admin/training/datasets/{batchId}/reject', [TrainingDatasetController::class, 'reject'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('batchId')
            ->name('admin.training-datasets.reject');
        Route::post('/admin/training/datasets/releases', [TrainingDatasetController::class, 'storeRelease'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.training-datasets.releases.store');
        Route::patch('/admin/training/datasets/{batchId}/examples/{row}', [TrainingDatasetController::class, 'updateExample'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('batchId')->whereNumber('row')
            ->name('admin.training-datasets.examples.update');
        Route::delete('/admin/training/datasets/{batchId}/examples/{row}', [TrainingDatasetController::class, 'deleteExample'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('batchId')->whereNumber('row')
            ->name('admin.training-datasets.examples.destroy');

        Route::post('/admin/training/adapters', [LoraAdapterController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:10,1'])
            ->name('admin.training-adapters.store');
        Route::post('/admin/training/adapters/{adapterId}/publish', [LoraAdapterController::class, 'publish'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('adapterId')
            ->name('admin.training-adapters.publish');
        Route::post('/admin/training/adapters/{adapterId}/rollback', [LoraAdapterController::class, 'rollback'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('adapterId')
            ->name('admin.training-adapters.rollback');
        Route::delete('/admin/training/adapters/{adapterId}', [LoraAdapterController::class, 'destroy'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('adapterId')
            ->name('admin.training-adapters.destroy');

        Route::post('/admin/training/research', [TrainingResearchController::class, 'draft'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:10,1'])
            ->name('admin.training-research.draft');
        Route::post('/admin/training/research/drafts', [TrainingResearchController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:10,1'])
            ->name('admin.training-research.store');
        Route::delete('/admin/training/research/drafts', [TrainingResearchController::class, 'cancel'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.training-research.cancel');

        Route::get('/admin/users', function () {
            return view('dashboard.users', [
                'users' => User::query()->orderBy('name')->paginate(10),
            ]);
        })->middleware(['role:admin', 'can:manage-users'])->name('admin.users');

        Route::post('/admin/users', [AdminUserController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.users.store');

        // Documents (RAG)
        Route::get('/admin/projects/{nexusProjectId}/documents', [DocumentController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.documents');
        Route::post('/admin/projects/{nexusProjectId}/documents', [DocumentController::class, 'upload'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.documents.upload');
        Route::get('/admin/documents/{documentId}/status', [DocumentController::class, 'status'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.documents.status');
        Route::post('/admin/documents/{documentId}/reindex', [DocumentController::class, 'reindex'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.documents.reindex');
        Route::post('/admin/documents/{documentId}/ask', [DocumentController::class, 'ask'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.documents.ask');
        Route::post('/admin/projects/{nexusProjectId}/documents/{documentId}/ask/stream', [DocumentController::class, 'askStream'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:20,1'])
            ->whereUuid('nexusProjectId')
            ->whereUuid('documentId')
            ->name('admin.documents.ask-stream');
        Route::post('/admin/projects/{nexusProjectId}/documents/analyze/stream', [DocumentController::class, 'analyzeStream'])
            ->middleware(['role:admin', 'can:manage-users', 'throttle:20,1'])
            ->whereUuid('nexusProjectId')
            ->name('admin.documents.analyze-stream');
        Route::delete('/admin/documents/{documentId}', [DocumentController::class, 'delete'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.documents.delete');

        // Conversations
        Route::get('/admin/projects/{nexusProjectId}/conversations', [ConversationController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.conversations');
        Route::get('/admin/conversations/{conversationId}/messages', [ConversationController::class, 'messages'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.conversations.messages');

        // Decisions
        Route::get('/admin/projects/{nexusProjectId}/decisions', [DecisionController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.decisions');
        Route::post('/admin/projects/{nexusProjectId}/decisions', [DecisionController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.decisions.store');
        Route::patch('/admin/decisions/{decisionId}', [DecisionController::class, 'update'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.decisions.update');
        Route::delete('/admin/decisions/{decisionId}', [DecisionController::class, 'destroy'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.decisions.destroy');

        // Tasks
        Route::get('/admin/projects/{nexusProjectId}/tasks', [TaskController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.tasks');
        Route::post('/admin/projects/{nexusProjectId}/tasks', [TaskController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->whereUuid('nexusProjectId')
            ->name('admin.tasks.store');
        Route::patch('/admin/tasks/{taskId}', [TaskController::class, 'update'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.tasks.update');
        Route::delete('/admin/tasks/{taskId}', [TaskController::class, 'destroy'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.tasks.destroy');

        // Audit
        Route::get('/admin/audit', [AuditController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.audit');

        // System Health
        Route::get('/admin/health', [HealthController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.health');

        // Expert CRUD
        Route::post('/admin/experts', [ExpertController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.experts.store');
        Route::patch('/admin/experts/{expertKey}', [ExpertController::class, 'update'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.experts.update');
        Route::delete('/admin/experts/{expertKey}', [ExpertController::class, 'destroy'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.experts.destroy');

        Route::get('/account/password/change', [PasswordChangeController::class, 'edit'])
            ->name('account.password.edit');
        Route::post('/account/password/change', [PasswordChangeController::class, 'update'])
            ->name('account.password.update');
    });
});
