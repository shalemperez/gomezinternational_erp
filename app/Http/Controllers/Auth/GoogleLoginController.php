<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginWithGoogle;
use App\Exceptions\LoginRejectedException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleLoginController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        $params = ['prompt' => 'select_account'];
        if ($domain = config('erp.google_workspace_domain')) {
            $params['hd'] = $domain;
        }

        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->with($params)->redirect();
    }

    public function callback(Request $request, LoginWithGoogle $action): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            if (class_exists(Activity::class)) {
                activity('auth')->event('login_rejected')->withProperties(['email' => null, 'reason' => 'oauth_error', 'ip' => $request->ip(), 'message' => $e->getMessage()])->log('Inicio de sesión rechazado');
            }

            return redirect()->route('login')->with('error', 'No se pudo completar el inicio de sesión con Google. Inténtalo de nuevo.');
        }

        try {
            $user = $action->handle($googleUser);
        } catch (LoginRejectedException $e) {
            if (class_exists(Activity::class)) {
                activity('auth')->event('login_rejected')->withProperties(['email' => $e->email, 'reason' => $e->reason, 'ip' => $request->ip()])->log('Inicio de sesión rechazado');
            }

            return redirect()->route('login')->with('error', $e->getMessage());
        }

        $request->session()->regenerate();
        if (class_exists(Activity::class)) {
            activity('auth')->causedBy($user)->event('login')->withProperties(['ip' => $request->ip()])->log('Inicio de sesión');
        }

        return redirect()->intended(route('dashboard'));
    }
}
