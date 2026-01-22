<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Получаем язык из сессии или из параметра запроса
        $locale = $request->get('lang') 
            ?? Session::get('locale') 
            ?? config('app.locale');

        // Проверяем, что язык поддерживается
        if (!in_array($locale, ['en', 'ru'])) {
            $locale = config('app.locale');
        }

        // Устанавливаем язык
        App::setLocale($locale);
        Session::put('locale', $locale);

        return $next($request);
    }
}
