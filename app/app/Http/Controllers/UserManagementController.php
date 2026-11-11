<?php

namespace App\Http\Controllers;

use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\NavidromeAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class UserManagementController extends Controller
{
    use ProfileValidationRules;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = trim($data['q'] ?? '');
        $users = User::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $pattern = '%'.Str::lower($query).'%';
                $builder->where(function ($search) use ($pattern): void {
                    $search->whereRaw('LOWER(name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(username) LIKE ?', [$pattern]);
                });
            })->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('users.index', ['users' => $users, 'query' => $query]);
    }

    public function edit(Request $request, User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.edit', [
            'managedUser' => $user,
            'roles' => $request->user()->role === 'owner' ? ['member', 'admin'] : ['member'],
            'accounts' => $user->serviceAccounts()->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $data = $request->validate([
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['member', 'admin'])],
            'email_confirmed' => ['sometimes', 'accepted'],
            'username' => ['prohibited'],
            'password' => ['prohibited'],
            'service_access' => ['prohibited'],
            'must_change_password' => ['prohibited'],
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            [$actor, $target] = $this->lockedUsers($request->user()->id, $user->id);
            Gate::forUser($actor)->authorize('update', $target);
            abort_unless($actor->canInviteRole($data['role']), 403);
            if ($target->email !== $data['email']) {
                if (! isset($data['email_confirmed'])) {
                    throw ValidationException::withMessages(['email_confirmed' => 'Confirma que has comprobado la titularidad del nuevo correo.']);
                }
                Password::broker()->deleteToken($target);
                Invitation::whereIn('email', [$target->email, $data['email']])->update(['expires_at' => now()]);
                $target->email_verified_at = now();
            }
            $target->name = $data['name'];
            $target->email = $data['email'];
            $target->role = $data['role'];
            $target->save();
        });

        return redirect()->route('users.edit', $user)->with('status', 'Usuario actualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $data = $request->validate(['confirm_email' => ['required', 'string']]);
        DB::transaction(function () use ($request, $user, $data): void {
            [$actor, $target] = $this->lockedUsers($request->user()->id, $user->id);
            Gate::forUser($actor)->authorize('delete', $target);
            if ($data['confirm_email'] !== $target->email) {
                throw ValidationException::withMessages(['confirm_email' => 'Escribe el correo de esta cuenta para confirmar.']);
            }
            $accounts = $target->serviceAccounts()->orderBy('id')->lockForUpdate()->get();
            if ($accounts->contains(fn ($account) => $account->service !== 'music')) {
                throw ValidationException::withMessages(['deletion' => 'Esta cuenta tiene un servicio cuya baja todavía no está automatizada.']);
            }
            try {
                foreach ($accounts as $account) {
                    app(NavidromeAccounts::class)->remove($account);
                }
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['deletion' => 'No se pudo confirmar la baja en Música. Revisa la conexión y vuelve a intentarlo. La cuenta de Multisuite se ha conservado.']);
            }
            Password::broker()->deleteToken($target);
            Invitation::where('email', $target->email)->update(['expires_at' => now()]);
            $target->delete();
        });

        return redirect()->route('users.index')->with('status', 'Usuario eliminado.');
    }

    /** @return array{User, User} */
    private function lockedUsers(int $actorId, int $targetId): array
    {
        // Recheck current roles inside the transaction, locking in a stable order.
        $users = User::whereIn('id', [$actorId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actorId);
        $target = $users->get($targetId);
        abort_unless($actor instanceof User && $target instanceof User, 404);

        return [$actor, $target];
    }
}
