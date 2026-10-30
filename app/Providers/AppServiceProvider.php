<?php

namespace App\Providers;

use App\Contracts\EcfProvider;
use App\Contracts\GmailSender;
use App\Listeners\LogAccessChanges;
use App\Models\ActivityLog;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\Receipt;
use App\Models\User;
use App\Policies\CreditNotePolicy;
use App\Policies\DebitNotePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ProformaPolicy;
use App\Policies\ReceiptPolicy;
use App\Services\Ecf\EcfService;
use App\Services\Mail\GoogleGmailSender;
use App\Services\Mail\LogGmailSender;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
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
        $this->app->bind(EcfProvider::class, fn () => $this->app->make(EcfService::class)->provider());
        $this->app->bind(GmailSender::class, fn () => config('erp.gmail.driver') === 'google' ? $this->app->make(GoogleGmailSender::class) : $this->app->make(LogGmailSender::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        self::configureTrustedProxies();

        Gate::before(fn (User $user, string $ability) => $user->isSuperAdmin() ? true : null);
        Gate::policy(Proforma::class, ProformaPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(CreditNote::class, CreditNotePolicy::class);
        Gate::policy(DebitNote::class, DebitNotePolicy::class);
        Gate::policy(Receipt::class, ReceiptPolicy::class);

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
     * Trust the X-Forwarded-* headers only from the proxies listed in config('erp.trusted_proxies').
     */
    public static function configureTrustedProxies(): void
    {
        TrustProxies::flushState();
        $trusted = (string) config('erp.trusted_proxies');
        if ($trusted !== '') {
            TrustProxies::at($trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
        }
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
