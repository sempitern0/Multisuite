<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\PortalInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function index(Request $request): View
    {
        Gate::authorize('manage-invitations');
        $roles = $request->user()?->role === 'owner' ? ['member', 'admin'] : ['member'];

        return view('invitations.index', [
            'roles' => $roles,
            'invitations' => Invitation::whereIn('role', $roles)->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'member'])],
        ]);
        abort_unless($request->user()?->canInviteRole($data['role']), 403);
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($data, $request, $token): Invitation {
            $invitation = Invitation::where('email', $data['email'])->lockForUpdate()->first() ?? new Invitation;
            // Administrators cannot overwrite an owner's administrator invitation.
            abort_if($invitation->exists && ! $request->user()?->canInviteRole($invitation->role), 403);
            $invitation->email = $data['email'];
            $invitation->role = $data['role'];
            $invitation->invited_by = $request->user()?->id;
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = now()->addDays(7);
            $invitation->used_at = null;
            $invitation->save();

            return $invitation;
        });
        $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, ['token' => $token]);
        Notification::route('mail', $invitation->email)->notify(new PortalInvitation($url));

        return back()->with('status', 'Invitación enviada. Cualquier enlace anterior ha quedado invalidado.');
    }

    public function revoke(Request $request, Invitation $invitation): RedirectResponse
    {
        Gate::authorize('manage-invitations');
        abort_unless($request->user()?->canInviteRole($invitation->role), 403);
        $invitation->expires_at = now();
        $invitation->save();

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
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'invited_by' => ['prohibited'],
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
            $user->email = $invitation->email;
            $user->password = $data['password'];
            $user->role = $invitation->role;
            // Possession of the link delivered to this mailbox proves email access.
            $user->email_verified_at = now();
            $user->save();

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
