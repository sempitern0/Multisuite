<?php

namespace App\Http\Controllers;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChangeController extends Controller
{
    use ProfileValidationRules;

    public function requestChange(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => [...$this->emailRules(), 'lowercase']])['email'];
        $token = Str::random(64);
        DB::transaction(function () use ($request, $email, $token): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('pending_email_changes')->upsert([
                'user_id' => $user->id, 'previous_email' => $user->email, 'email' => $email,
                'token_hash' => hash('sha256', $token),
            ], ['user_id'], ['previous_email', 'email', 'token_hash']);
        });
        $url = URL::temporarySignedRoute('email-change.confirm', now()->addMinutes(30), ['token' => $token]);
        Mail::raw("Confirma tu nuevo correo en Multisuite. El enlace caduca en 30 minutos.\n\n".$url,
            fn (Message $message) => $message->to($email)->subject('Confirma tu nuevo correo en Multisuite'));

        return redirect()->route('profile.edit')->with('status', 'Revisa tu nuevo correo para confirmar el cambio.');
    }

    public function show(Request $request, string $token): View
    {
        $pending = DB::table('pending_email_changes')->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $token))->where('previous_email', $request->user()->email)->first();
        abort_if($pending === null, 410);

        return view('settings.confirm-email-change', ['email' => $pending->email]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $previous = DB::transaction(function () use ($request, $token): string {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $pending = DB::table('pending_email_changes')->where('user_id', $user->id);
            $row = (clone $pending)->where('token_hash', hash('sha256', $token))->where('previous_email', $user->email)->first();
            abort_if($row === null, 410);
            if (User::where('email', $row->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Ese correo ya está en uso.']);
            }
            $previous = $user->email;
            Password::broker()->deleteToken($user);
            $user->email = $row->email;
            Password::broker()->deleteToken($user);
            $user->email_verified_at = now();
            $user->save();
            $pending->delete();

            return $previous;
        });
        Mail::raw('Se ha cambiado el correo de tu cuenta Multisuite. Si no lo solicitaste, contacta con quien administra el portal.',
            fn (Message $message) => $message->to($previous)->subject('Cambio de correo en Multisuite'));

        return redirect()->route('profile.edit')->with('status', 'Nuevo correo confirmado.');
    }
}
