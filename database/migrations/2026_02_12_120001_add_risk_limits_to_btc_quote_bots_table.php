<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('btc_quote_bots', function (Blueprint $table) {
            $table->decimal('max_daily_loss_btc', 16, 8)->nullable()->after('take_profit_percent')
                ->comment('При достижении дневного убытка по боту (BTC) новые BUY пропускаются');
            $table->unsignedTinyInteger('max_losing_streak')->nullable()->after('max_daily_loss_btc')
                ->comment('После N убыточных сделок подряд по боту новые BUY пропускаются до след. дня');
        });
    }

    public function down(): void
    {
        Schema::table('btc_quote_bots', function (Blueprint $table) {
            $table->dropColumn(['max_daily_loss_btc', 'max_losing_streak']);
        });
    }
};
