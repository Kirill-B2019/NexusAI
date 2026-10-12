<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\PasswordChangeController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'password.change'])->group(function () {
    Route::view('/dashboard', 'dashboard.index')->name('dashboard');

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
