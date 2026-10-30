<?php

namespace App\Actions\Auth;

use App\Actions\Admin\SyncSuperAdmins;
use App\Exceptions\LoginRejectedException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as GoogleUser;

class LoginWithGoogle
{
    public function __construct(private readonly SyncSuperAdmins $syncSuperAdmins) {}

    public function handle(GoogleUser $googleUser): User
    {
        $raw = $googleUser instanceof \Laravel\Socialite\Two\User ? $googleUser->getRaw() : [];
        $email = strtolower(trim((string) $googleUser->getEmail()));
        $domain = strtolower(trim((string) config('erp.google_workspace_domain')));
        $isSupport = in_array($email, config('erp.super_admin_emails', []), true);

        if (($raw['email_verified'] ?? false) !== true) {
            throw new LoginRejectedException('Google no ha verificado el correo de esta cuenta.', $email, 'unverified');
        }

        if (! $isSupport) {
            $hd = $raw['hd'] ?? null;
            if ($hd === null) {
                throw new LoginRejectedException("Solo se permite iniciar sesión con una cuenta de Google Workspace de {$domain}.", $email, 'hd_missing');
            }
            if ($domain === '' || strtolower((string) $hd) !== $domain) {
                throw new LoginRejectedException("Solo se permite iniciar sesión con una cuenta de Google Workspace de {$domain}.", $email, 'domain');
            }
        }

        if ($isSupport) {
            $this->syncSuperAdmins->handle();
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            throw new LoginRejectedException('Tu cuenta no está registrada en el sistema. Pide a un administrador que te invite.', $email, 'not_invited');
        }

        if (! $user->is_active) {
            throw new LoginRejectedException('Tu cuenta está desactivada. Contacta a un administrador.', $email, 'inactive');
        }

        $googleId = (string) $googleUser->getId();
        if ($user->google_id !== null && $user->google_id !== $googleId) {
            throw new LoginRejectedException('Esta cuenta de Google no coincide con el usuario registrado. Contacta a un administrador.', $email, 'identity_mismatch');
        }

        $user->forceFill([
            'google_id' => $googleId,
            'avatar_url' => $googleUser->getAvatar(),
            'last_login_at' => now(),
        ])->save();

        Auth::guard('web')->login($user, remember: true);

        return $user;
    }
}
