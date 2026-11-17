<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Services\ReadingSession;
use App\Services\ServiceAccounts;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordConfirmation();
    }

    /**
     * Require a recent password confirmation for Livewire requests from pages that require one.
     */
    private function configurePasswordConfirmation(): void
    {
        Livewire::addPersistentMiddleware([
            RequirePassword::class,
        ]);
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(function (Request $request): ?User {
            $identifier = Str::lower(trim((string) $request->input('email')));
            $user = User::where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)->first();

            $password = (string) $request->input('password');
            if (! $user || ! Hash::check($password, $user->password)) {
                return null;
            }
            // Told only to someone who already proved the password.
            if ($user->isSuspended()) {
                throw ValidationException::withMessages([Fortify::username() => 'Esta cuenta está suspendida. Habla con quien administra Multisuite.']);
            }
            // Services waiting for a password (access granted again) receive it now, after the response.
            app(ServiceAccounts::class)->resume($user, $password);

            app(ReadingSession::class)->prepare($user, $password);

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $identifier = Str::lower(trim((string) $request->input(Fortify::username())));
            $user = User::where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)->first();
            $throttleKey = ($user ? 'user:'.$user->id : 'login:'.$identifier).'|'.$request->ip();

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
