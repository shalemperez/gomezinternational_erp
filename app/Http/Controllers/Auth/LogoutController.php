<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (class_exists(Activity::class) && $request->user()) {
            activity('auth')->causedBy($request->user())->event('logout')->withProperties(['ip' => $request->ip()])->log('Cierre de sesión');
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
