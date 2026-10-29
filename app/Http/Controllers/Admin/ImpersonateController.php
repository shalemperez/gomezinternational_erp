<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    #[Middleware('permission:admin.users.impersonate')]
    public function start(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_if($user->is($actor), 403, 'No puedes entrar como tú mismo.');
        abort_if(! $user->is_active, 403, 'El usuario está desactivado.');
        abort_if($user->isSuperAdmin(), 403, 'No se puede entrar como otro Super Admin.');

        activity('auth')->causedBy($actor)->performedOn($user)->event('impersonation_started')->withProperties(['ip' => $request->ip()])->log("Entró como {$user->name}");
        $request->session()->put('impersonator_id', $actor->id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', "Ahora estás viendo el sistema como {$user->name}");
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }
        $impersonator = User::query()->whereKey($impersonatorId)->firstOrFail();
        $impersonated = $request->user();
        $request->session()->forget('impersonator_id');
        activity('auth')->causedBy($impersonator)->performedOn($impersonated)->event('impersonation_stopped')->log("Salió del modo de suplantación de {$impersonated->name}");
        Auth::guard('web')->login($impersonator);
        $request->session()->regenerate();

        return redirect()->route('admin.users.index')->with('success', 'Has vuelto a tu cuenta');
    }
}
