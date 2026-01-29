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
            $table->decimal('max_daily_loss_usdt', 12, 2)->nullable()->after('take_profit_percent');
            $table->decimal('max_drawdown_percent', 5, 2)->nullable()->after('max_daily_loss_usdt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn(['max_daily_loss_usdt', 'max_drawdown_percent']);
        });
    }
};
