<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManualUserController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(Request $request): View
    {
        Gate::authorize('manage-invitations');

        return view('users.create', [
            'roles' => $request->user()->role === 'owner' ? ['member', 'admin'] : ['member'],
            'services' => app(ServiceAccounts::class)->available(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        $data = $request->validate([
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'member'])],
            'password' => $this->passwordRules(),
            'services' => ['sometimes', 'array'],
            'services.*' => ['string', 'distinct', Rule::in(app(ServiceAccounts::class)->available())],
        ]);

        abort_unless($request->user()->canInviteRole($data['role']), 403);

        DB::transaction(function () use ($data, $request): void {
            $invitation = Invitation::where('email', $data['email'])->lockForUpdate()->first();
            abort_if($invitation && ! $request->user()->canInviteRole($invitation->role), 403);

            // The administrator vouches for the email address. No message is sent.
            // Service credentials are intentionally NOT provisioned with the temporary password.
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->role = $data['role'];
            $user->service_access = $data['services'] ?? [];
            $user->email_verified_at = now();
            $user->must_change_password = true;
            $user->created_by_admin_id = $request->user()->id;
            $user->save();

            if ($invitation) {
                $invitation->expires_at = now();
                $invitation->save();
            }
        });

        return redirect()->route('users.create')
            ->with('status', 'Cuenta creada. Entrega la contraseña temporal por un canal seguro: se exigirá cambiarla en el primer acceso.');
    }
}
