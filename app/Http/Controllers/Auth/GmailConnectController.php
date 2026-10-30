<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class GmailConnectController extends Controller
{
    public function connect(Request $request): SymfonyRedirect
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->scopes([config('erp.gmail.scope')])
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $request->user()->email])
            ->redirectUrl(route('auth.gmail.callback'))
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');
        $googleUser = $driver->redirectUrl(route('auth.gmail.callback'))->user();
        $rawGoogleUser = $googleUser instanceof GoogleUser ? $googleUser : null;
        $user = $request->user();

        if ($rawGoogleUser === null) {
            return redirect()->route('dashboard')->with('error', 'Google no devolvió un token de acceso persistente; vuelve a intentarlo.');
        }
        if (strtolower($googleUser->getEmail()) !== strtolower($user->email)) {
            return redirect()->route('dashboard')->with('error', "Debes conectar la misma cuenta de Google con la que iniciaste sesión ({$user->email}).");
        }
        if (! in_array(config('erp.gmail.scope'), $rawGoogleUser->approvedScopes, true)) {
            return redirect()->route('dashboard')->with('error', 'Google no concedió el permiso para enviar correos (gmail.send).');
        }
        if (empty($rawGoogleUser->refreshToken)) {
            return redirect()->route('dashboard')->with('error', 'Google no devolvió un token de acceso persistente; vuelve a intentarlo.');
        }

        $user->forceFill([
            'gmail_refresh_token' => $rawGoogleUser->refreshToken,
            'gmail_email' => strtolower($googleUser->getEmail()),
            'gmail_connected_at' => now(),
        ])->save();
        activity('auth')->causedBy($user)->event('gmail_connected')->log('Gmail conectado');

        return redirect()->route('dashboard')->with('success', 'Gmail conectado: '.strtolower($googleUser->getEmail()));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['gmail_refresh_token' => null, 'gmail_email' => null, 'gmail_connected_at' => null])->save();
        activity('auth')->causedBy($user)->event('gmail_disconnected')->log('Gmail desconectado');

        return back()->with('success', 'Gmail desconectado');
    }
}
