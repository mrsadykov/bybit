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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            // какой бот сделал сделку
            $table->foreignId('trading_bot_id')
                ->constrained('trading_bots')->cascadeOnDelete();

            // BUY or SELL
            $table->string('side');

            // Торговая пара BTCUSDT
            $table->string('symbol');

            // Цена исполнения
            $table->decimal('price', 16, 8);

            // position_size → план
            // quantity → что реально произошло
            // Количество (BTC)
            $table->decimal('quantity', 16, 8);

            // Статус сделки
            $table->string('status');
            // PENDING - отправили ордер
            // FILLED - ордер исполнен
            // FAILED - ошибка (недостаточно средств, лимиты и т.п.)

            // Ответ биржи (для дебага)
            $table->json('exchange_response')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
