<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard.index')->name('dashboard');

    Route::get('/admin/users', function () {
        return view('dashboard.users', [
            'users' => User::query()->orderBy('name')->paginate(10),
        ]);
    })->middleware(['role:admin', 'can:manage-users'])->name('admin.users');
});
