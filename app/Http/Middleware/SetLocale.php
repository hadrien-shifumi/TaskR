<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Applies the locale for the current request: the authenticated user's
     * persisted preference when supported, the application default otherwise.
     *
     * @param  Closure(Request): Response  $next
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $locales = array_keys(config('i18n.locales', []));
        $locale = $request->user()?->locale;

        App::setLocale(in_array($locale, $locales, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
