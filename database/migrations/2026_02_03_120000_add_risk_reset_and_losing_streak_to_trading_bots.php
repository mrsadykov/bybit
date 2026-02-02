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
            $table->timestamp('risk_drawdown_reset_at')->nullable()->after('max_drawdown_percent');
            $table->unsignedTinyInteger('max_losing_streak')->nullable()->after('risk_drawdown_reset_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn(['risk_drawdown_reset_at', 'max_losing_streak']);
        });
    }
};
