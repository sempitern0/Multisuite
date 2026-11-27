<?php

namespace App\Providers;

use App\Events\AssistantContentRevoked;
use App\Events\PrivateUploadReady;
use App\Models\Folder;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Models\UserFile;
use App\Policies\FolderPolicy;
use App\Policies\PersonalTransactionPolicy;
use App\Policies\UserFilePolicy;
use App\Policies\UserPolicy;
use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\SemanticIndex;
use App\Services\Files\PersonalFiles;
use App\Services\Files\Publications;
use App\Services\VideoSession;
use App\Support\Finance\ReadsReceipts;
use App\Support\Finance\ReceiptReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReadsReceipts::class, ReceiptReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        // Behind the private entry the scheme and host come from X-Forwarded-*: only from these proxies.
        /** @var list<string> $proxies */
        $proxies = config('app.trusted_proxies', []);
        TrustProxies::at($proxies);
        // Design layer: `<x-ui::button>` etc. resolve to the active theme's components.
        Blade::anonymousComponentPath(resource_path('views/themes/'.config('ui.theme', 'nocturne').'/components'), 'ui');
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserFile::class, UserFilePolicy::class);
        Gate::policy(Folder::class, FolderPolicy::class);
        Gate::policy(PersonalTransaction::class, PersonalTransactionPolicy::class);
        // External MCP clients (MCP-05): our own consent screen, short access tokens.
        Passport::authorizationView('pages.oauth-authorize');
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        // Named limiters: unnamed `throttle:N,1` share one counter per user across every
        // route (and nested ones count twice), so each area gets its own key.
        foreach (['files' => 120, 'uploads' => 60, 'upload-chunks' => 1200, 'receipt-ocr' => 4, 'finances' => 60, 'file-exports' => 10, 'invitation-accept' => 10, 'account-change' => 5, 'avatar-sticker' => 30, 'admin' => 10, 'admin-storage' => 60, 'assistant' => 20, 'assistant-mcp' => 60, 'mcp-oauth' => 10] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
        // Registered here (no app/Listeners) so event discovery cannot attach it twice.
        Event::listen(PrivateUploadReady::class, fn (PrivateUploadReady $event) => app(PersonalFiles::class)->onUploadReady($event->uploadId, $event->userId));
        // Signing out of Multisuite also ends the Jellyfin session it opened for that browser.
        Event::listen(Logout::class, fn () => app(VideoSession::class)->forget());
        Event::listen(AssistantContentRevoked::class, fn (AssistantContentRevoked $event) => app(SemanticIndex::class)->forget($event->userId));
        Gate::define('view-admin-audit', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('manage-invitations', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('view-storage', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        // Disks and the default are infrastructure policy: owner only.
        // Publication for everyone (S04b): only administrators decide.
        Gate::define('publish-content', fn (User $user): bool => Publications::canDecide($user));
        Gate::define('manage-storage-pools', fn (User $user): bool => $user->role === 'owner');
        // The assistant is configured only by the owner (decision 2026-11-20, docs/MCP.md).
        Gate::define('manage-assistant', fn (User $user): bool => $user->role === 'owner');
        // Mis finanzas can be switched off by the operator (PORTAL_FINANCES=false); otherwise everyone has their own.
        Gate::define('use-finances', fn (User $user): bool => (bool) config('portal.finances.enabled'));
        Gate::define('use-assistant', fn (User $user): bool => app(AssistantAccess::class)->denial($user) === null);
        // Owner manages everyone (including self); admins only members, as in UserPolicy.
        Gate::define('manage-storage-quota', fn (User $actor, User $target): bool => $actor->role === 'owner'
            || ($actor->role === 'admin' && $actor->id !== $target->id && $target->role === 'member'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
