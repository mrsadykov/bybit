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
    Route::get('/trades/export', [TradesController::class, 'export'])->name('trades.export');

    // Decision log (логи решений ботов)
    Route::get('/decision-log', [\App\Http\Controllers\BotDecisionLogController::class, 'index'])->name('decision-log.index');
    
    // Trading Bots routes
    Route::resource('bots', \App\Http\Controllers\BotController::class);
    Route::post('bots/{bot}/toggle-active', [\App\Http\Controllers\BotController::class, 'toggleActive'])
        ->name('bots.toggle-active');

    // Futures Bots routes (OKX perpetual swap)
    Route::resource('futures-bots', \App\Http\Controllers\FuturesBotController::class)->parameters(['futures-bots' => 'futures_bot']);
    Route::post('futures-bots/{futures_bot}/toggle-active', [\App\Http\Controllers\FuturesBotController::class, 'toggleActive'])
        ->name('futures-bots.toggle-active');

    // BTC-quote Bots routes (trade alts for BTC on OKX)
    Route::resource('btc-quote-bots', \App\Http\Controllers\BtcQuoteBotController::class)->parameters(['btc-quote-bots' => 'btc_quote_bot']);
    Route::post('btc-quote-bots/{btc_quote_bot}/toggle-active', [\App\Http\Controllers\BtcQuoteBotController::class, 'toggleActive'])
        ->name('btc-quote-bots.toggle-active');
});

require __DIR__.'/auth.php';
