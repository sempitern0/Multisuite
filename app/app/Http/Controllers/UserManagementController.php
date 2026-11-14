<?php

namespace App\Http\Controllers;

use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Form endpoints kept for clients without JavaScript; the pages are Livewire
 * (resources/views/pages/admin) and both use the same Actions.
 */
class UserManagementController extends Controller
{
    public function update(Request $request, User $user, UpdateUser $update): RedirectResponse
    {
        Gate::authorize('update', $user);
        /** @var array{name: string, email: string, role: string, email_confirmed?: mixed} $data */
        $data = $request->validate($update->rules($user) + [
            'username' => ['prohibited'],
            'password' => ['prohibited'],
            'service_access' => ['prohibited'],
            'must_change_password' => ['prohibited'],
        ]);
        $update->handle($request->user(), $user, $data);

        return redirect()->route('users.edit', $user)->with('toast', 'Cambios guardados');
    }

    public function destroy(Request $request, User $user, DeleteUser $delete): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $data = $request->validate(['confirm_email' => ['required', 'string']]);
        $delete->handle($request->user(), $user, $data['confirm_email']);

        return redirect()->route('users.index')->with('toast', 'Persona eliminada');
    }
}
