<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btc_quote_bots', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('exchange_account_id')->constrained('exchange_accounts')->cascadeOnDelete();
            $table->string('symbol', 20); // SOLBTC, ETHBTC, BNBBTC
            $table->string('timeframe', 10);
            $table->string('strategy', 50)->default('rsi_ema');
            $table->decimal('position_size_btc', 16, 8); // размер позиции в BTC
            $table->unsignedTinyInteger('rsi_period')->nullable();
            $table->unsignedSmallInteger('ema_period')->nullable();
            $table->decimal('rsi_buy_threshold', 8, 2)->nullable();
            $table->decimal('rsi_sell_threshold', 8, 2)->nullable();
            $table->decimal('stop_loss_percent', 8, 2)->nullable();
            $table->decimal('take_profit_percent', 8, 2)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('dry_run')->default(true);
            $table->timestamp('last_trade_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btc_quote_bots');
    }
};
