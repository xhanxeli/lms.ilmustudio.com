<?php

namespace App\Http\Middleware;

use App\Models\AiContentTemplate;
use Closure;
use Illuminate\Support\Facades\Auth;

class PanelAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!auth()->user() and !empty(apiAuth())) {
            auth()->setUser(apiAuth());
        }

        // Check if user is authenticated and not an admin
        if (auth()->check() and !auth()->user()->isAdmin()) {

            $referralSettings = getReferralSettings();
            view()->share('referralSettings', $referralSettings);

            $aiContentTemplates = AiContentTemplate::query()->where('enable', true)->get();
            view()->share('aiContentTemplates', $aiContentTemplates);

            return $next($request);
        }

        // If user is authenticated and is admin, redirect to admin panel
        if (auth()->check() and auth()->user()->isAdmin()) {
            return redirect(getAdminPanelUrl());
        }

        // Not authenticated - redirect to login and store intended URL
        return redirect()->guest('/login');
    }
}
