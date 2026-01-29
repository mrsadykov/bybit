<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * RSI buy/sell thresholds per bot (e.g. 40/60, 45/55). Null = use defaults (40/60 live, 45/55 backtest).
     */
    public function up(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->decimal('rsi_buy_threshold', 5, 2)->nullable()->after('ema_period')->comment('RSI buy threshold (e.g. 40). Null = default.');
            $table->decimal('rsi_sell_threshold', 5, 2)->nullable()->after('rsi_buy_threshold')->comment('RSI sell threshold (e.g. 60). Null = default.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn(['rsi_buy_threshold', 'rsi_sell_threshold']);
        });
    }
};
