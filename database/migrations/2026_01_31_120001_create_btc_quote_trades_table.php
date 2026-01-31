<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btc_quote_trades', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('btc_quote_bot_id')->constrained('btc_quote_bots')->cascadeOnDelete();
            $table->string('side', 10); // BUY, SELL
            $table->string('symbol', 20);
            $table->decimal('price', 16, 8); // цена в BTC за 1 единицу базового актива
            $table->decimal('quantity', 16, 8); // количество базового актива (SOL, ETH, ...)
            $table->decimal('fee', 16, 8)->nullable();
            $table->string('fee_currency', 10)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('filled_at')->nullable();
            $table->string('order_id', 64)->nullable();
            $table->json('exchange_response')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('realized_pnl_btc', 16, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btc_quote_trades');
    }
};
