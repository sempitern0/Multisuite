<?php

namespace App\Providers;

use App\Events\PrivateUploadReady;
use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
use App\Policies\FolderPolicy;
use App\Policies\UserFilePolicy;
use App\Policies\UserPolicy;
use App\Services\Files\PersonalFiles;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        // Design layer: `<x-ui::button>` etc. resolve to the active theme's components.
        Blade::anonymousComponentPath(resource_path('views/themes/'.config('ui.theme', 'nocturne').'/components'), 'ui');
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserFile::class, UserFilePolicy::class);
        Gate::policy(Folder::class, FolderPolicy::class);
        // Named limiters: unnamed `throttle:N,1` share one counter per user across every
        // route (and nested ones count twice), so each area gets its own key.
        foreach (['files' => 120, 'uploads' => 60, 'file-exports' => 10, 'invitation-accept' => 10, 'account-change' => 5, 'admin' => 10, 'admin-storage' => 60] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
        // Registered here (no app/Listeners) so event discovery cannot attach it twice.
        Event::listen(PrivateUploadReady::class, fn (PrivateUploadReady $event) => app(PersonalFiles::class)->onUploadReady($event->uploadId, $event->userId));
        Gate::define('view-admin-audit', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('manage-invitations', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('view-storage', fn (User $user): bool => in_array($user->role, ['owner', 'admin'], true));
        // Disks and the default are infrastructure policy: owner only.
        Gate::define('manage-storage-pools', fn (User $user): bool => $user->role === 'owner');
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
