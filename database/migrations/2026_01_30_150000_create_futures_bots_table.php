<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('futures_bots', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('exchange_account_id')->constrained('exchange_accounts')->cascadeOnDelete();
            $table->string('symbol', 20); // BTCUSDT, ETHUSDT, etc.
            $table->string('timeframe', 10)->default('1h');
            $table->string('strategy', 50)->default('rsi_ema');
            $table->unsignedTinyInteger('rsi_period')->default(17);
            $table->unsignedTinyInteger('ema_period')->default(10);
            $table->decimal('rsi_buy_threshold', 5, 2)->nullable();
            $table->decimal('rsi_sell_threshold', 5, 2)->nullable();
            $table->decimal('position_size_usdt', 16, 2); // размер позиции в USDT (маржа × плечо ≈ exposure)
            $table->unsignedTinyInteger('leverage')->default(2); // 2–3x минимальный риск
            $table->decimal('stop_loss_percent', 5, 2)->nullable();
            $table->decimal('take_profit_percent', 5, 2)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('dry_run')->default(true); // по умолчанию тестнет/симуляция
            $table->timestamp('last_trade_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('futures_bots');
    }
};
