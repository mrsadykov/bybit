<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TradesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Language switching
Route::get('/locale/{locale}', [\App\Http\Controllers\LocaleController::class, 'setLocale'])
    ->name('locale.set');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Trades routes
    Route::get('/trades', [TradesController::class, 'index'])->name('trades.index');
    
    // Trading Bots routes
    Route::resource('bots', \App\Http\Controllers\BotController::class);
    Route::post('bots/{bot}/toggle-active', [\App\Http\Controllers\BotController::class, 'toggleActive'])
        ->name('bots.toggle-active');
});

require __DIR__.'/auth.php';
