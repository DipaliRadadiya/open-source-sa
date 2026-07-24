<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language', config('app.locale'));

        if (in_array($locale, config('app.available_locales'), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
