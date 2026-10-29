<?php

namespace App\Providers;

use App\Listeners\LogAccessChanges;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::before(fn (User $user, string $ability) => $user->isSuperAdmin() ? true : null);

        ActivityLog::creating(function (ActivityLog $activity): void {
            // `request.id` is bound only by AssignRequestId (HTTP); console and queue work have none.
            if (! app()->bound('request.id')) {
                $activity->user_agent = 'console';

                return;
            }
            $request = request();
            $activity->request_id = app('request.id');
            $activity->ip = $request->ip();
            $activity->user_agent = Str::limit((string) $request->userAgent(), 500, '');
            if ($request->hasSession() && ($impersonatorId = $request->session()->get('impersonator_id'))) {
                $activity->impersonator_id = $impersonatorId;
            }
        });

        Event::listen([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class], LogAccessChanges::class);
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
