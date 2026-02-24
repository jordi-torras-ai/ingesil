<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = User::supportedLocales();
        $locale = session('locale');
        $user = auth()->user();

        if ($user instanceof User && in_array($user->locale, $supportedLocales, true)) {
            $locale = $user->locale;
            session(['locale' => $locale]);
        }

        if (! in_array($locale, $supportedLocales, true)) {
            $locale = config('app.locale', User::LOCALE_EN);
        }

        App::setLocale($locale);

        return $next($request);
    }
}
