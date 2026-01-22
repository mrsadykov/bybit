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
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->integer('rsi_period')->nullable()->after('strategy')->comment('RSI период (по умолчанию 17)');
            $table->integer('ema_period')->nullable()->after('rsi_period')->comment('EMA период (по умолчанию 10)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn(['rsi_period', 'ema_period']);
        });
    }
};
