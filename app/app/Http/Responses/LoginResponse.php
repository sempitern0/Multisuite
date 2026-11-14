<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/** After signing in, go to the page the person chose as start (or the intended URL). */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    /** @param Request $request */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }
        $user = $request->user();

        return redirect()->intended(route($user !== null ? $user->preferences()->startRoute() : 'dashboard'));
    }
}
