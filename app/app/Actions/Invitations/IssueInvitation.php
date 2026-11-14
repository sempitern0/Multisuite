<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\PortalInvitation;
use App\Services\ServiceAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Issues (or reissues) the single invitation for an email. Shared by the HTTP
 * controller and the Livewire page so both apply the same rules.
 */
class IssueInvitation
{
    public function __construct(private readonly ServiceAccounts $accounts) {}

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'lowercase', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'member'])],
            'services' => ['sometimes', 'array'],
            'services.*' => ['string', 'distinct', Rule::in($this->accounts->available())],
        ];
    }

    /** @param array{email: string, role: string, services?: list<string>} $data validated with rules() */
    public function handle(User $issuer, array $data): Invitation
    {
        abort_unless($issuer->canInviteRole($data['role']), 403);
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($data, $issuer, $token): Invitation {
            $invitation = Invitation::where('email', $data['email'])->lockForUpdate()->first() ?? new Invitation;
            // Administrators cannot overwrite an owner's administrator invitation.
            abort_if($invitation->exists && ! $issuer->canInviteRole($invitation->role), 403);
            $invitation->email = $data['email'];
            $invitation->role = $data['role'];
            $invitation->services = $data['services'] ?? [];
            $invitation->invited_by = $issuer->id;
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = now()->addDays(7);
            $invitation->used_at = null;
            $invitation->save();

            return $invitation;
        });
        $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, ['token' => $token]);
        Notification::route('mail', $invitation->email)->notify(new PortalInvitation($url));

        return $invitation;
    }
}
