<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class LocaleController extends Controller
{
    public function setLocale($locale)
    {
        // Проверяем, что язык поддерживается
        if (!in_array($locale, ['en', 'ru'])) {
            return redirect()->back();
        }

        // Устанавливаем язык в сессию
        Session::put('locale', $locale);
        App::setLocale($locale);

        return redirect()->back();
    }
}
