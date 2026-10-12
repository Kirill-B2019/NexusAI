<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    use PasswordValidationRules;

    public function edit(): View
    {
        return view('auth.change-password');
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Пароль успешно изменён.');
    }
}
