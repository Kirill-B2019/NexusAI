<?php

use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ExpertController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SessionActivityController;
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
        Route::view('/dashboard', 'dashboard.index')->name('dashboard');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->middleware('can:view-projects')
            ->name('projects');
        Route::get('/chat', [ProjectController::class, 'chat'])
            ->middleware('can:view-projects')
            ->name('chat');
        Route::post('/chat/stream', ChatStreamController::class)
            ->middleware(['can:view-projects', 'throttle:20,1'])
            ->name('chat.stream');

        Route::get('/admin/projects', [AdminProjectController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.projects');
        Route::post('/admin/projects', [AdminProjectController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.projects.store');

        Route::get('/admin/experts', [ExpertController::class, 'index'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.experts');

        Route::get('/admin/users', function () {
            return view('dashboard.users', [
                'users' => User::query()->orderBy('name')->paginate(10),
            ]);
        })->middleware(['role:admin', 'can:manage-users'])->name('admin.users');

        Route::post('/admin/users', [AdminUserController::class, 'store'])
            ->middleware(['role:admin', 'can:manage-users'])
            ->name('admin.users.store');

        Route::get('/account/password/change', [PasswordChangeController::class, 'edit'])
            ->name('account.password.edit');
        Route::post('/account/password/change', [PasswordChangeController::class, 'update'])
            ->name('account.password.update');
    });
});
