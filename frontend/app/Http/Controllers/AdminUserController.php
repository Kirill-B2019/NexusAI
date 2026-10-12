<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $temporaryPassword = Str::password(32);

        User::query()->forceCreate([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $temporaryPassword,
            'role' => UserRole::from($validated['role']),
            'must_change_password' => true,
        ]);

        return redirect()->route('admin.users')
            ->with('new_user_credentials', [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $temporaryPassword,
            ]);
    }
}
