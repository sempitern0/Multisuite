<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Services\ServiceAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InitialPasswordController extends Controller
{
    use PasswordValidationRules;

    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('users.initial-password');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->must_change_password, 403);

        $data = $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        if (Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la temporal.']);
        }

        app(ServiceAccounts::class)->changePassword($request->user(), $data['password']);
        $request->session()->regenerate();
        $request->session()->forget('auth.password_confirmed_at');

        return redirect()->route('dashboard')->with('status', 'Contraseña personal establecida.');
    }
}
