<?php

namespace App\Http\Controllers;

use App\Concerns\UsernameValidationRules;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsernameController extends Controller
{
    use UsernameValidationRules;

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', ...$this->usernameRules()],
            'current_password' => ['required', 'string', 'current_password'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->username !== null) {
                throw ValidationException::withMessages(['username' => 'El nombre de usuario ya está establecido.']);
            }
            $user->username = $data['username'];
            $user->save();
            // Reuse the current password, including encrypted retry/expiry handling.
            app(ServiceAccounts::class)->changePassword($user, $data['current_password']);
        });

        return back()->with('status', 'Nombre de usuario guardado. Consulta el estado de Música en la portada.');
    }
}
