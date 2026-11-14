<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Form endpoint kept for clients without JavaScript; the page is Livewire and uses the same Action. */
class ManualUserController extends Controller
{
    public function store(Request $request, CreateUser $create): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        /** @var array{name: string, username?: ?string, email: string, role: string, password: string, services?: list<string>} $data */
        $data = $request->validate($create->rules());
        $create->handle($request->user(), $data);

        return redirect()->route('users.create')->with('toast', 'Cuenta creada');
    }
}
