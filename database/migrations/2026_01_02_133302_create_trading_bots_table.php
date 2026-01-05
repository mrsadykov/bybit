<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trading_bots', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Через какой API аккаунт торговать
            $table->foreignId('exchange_account_id')->constrained('exchange_accounts')->cascadeOnDelete();
            // Торговая пара
            $table->string('symbol', 20);
            // Таймфрейм свечей // какие свечи анализируем
            $table->string('timeframe', 10);
            // Стратегия (rsi_ema, macd и т.п.)
            $table->string('strategy', 50);
            // Сколько актива покупать или продавать за один ордер (в базовой валюте, напр. 0.001 BTC)
            // UPD: Сумма ордера в USDT (quote currency)
            $table->decimal('position_size', 16, 8);
            // Активен ли бот
            $table->boolean('is_active')->default(false);
            // Когда последний раз работал бот
            $table->timestamp('last_trade_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_bots');
    }
};
