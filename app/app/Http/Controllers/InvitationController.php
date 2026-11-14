<?php

namespace App\Http\Controllers;

use App\Actions\Invitations\IssueInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\UsernameValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules, UsernameValidationRules;

    public function store(Request $request, IssueInvitation $issue): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        /** @var array{email: string, role: string, services?: list<string>} $data */
        $data = $request->validate($issue->rules());
        $issue->handle($this->actor($request), $data);

        return back()->with('status', 'Invitación enviada. Cualquier enlace anterior ha quedado invalidado.');
    }

    public function revoke(Request $request, Invitation $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        $revoke->handle($this->actor($request), $invitation);

        return back()->with('status', 'Invitación revocada. Las cuentas existentes no cambian.');
    }

    public function show(string $token): Response
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invitation->isAcceptable(), 410);

        return response()->view('invitations.accept', compact('invitation'))
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'invited_by' => ['prohibited'],
            'services' => ['prohibited'],
        ]);
        $user = DB::transaction(function () use ($data, $token): User {
            $invitation = Invitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_unless($invitation->isAcceptable(), 410);
            abort_if(User::where('email', $invitation->email)->exists(), 410);
            $consumed = Invitation::whereKey($invitation->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->update(['used_at' => now()]);
            abort_unless($consumed === 1, 410);
            $user = new User;
            $user->name = $data['name'];
            $user->username = $data['username'] ?? null;
            $user->email = $invitation->email;
            $user->password = $data['password'];
            $user->role = $invitation->role;
            $user->service_access = $invitation->services ?? [];
            // Possession of the link delivered to this mailbox proves email access.
            $user->email_verified_at = now();
            $user->save();
            app(ServiceAccounts::class)->enroll($user, $invitation->services ?? [], $data['password']);

            return $user;
        });
        app(ServiceAccounts::class)->syncUser($user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
