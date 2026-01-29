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
            $table->boolean('use_macd_filter')->default(false)->after('max_drawdown_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn('use_macd_filter');
        });
    }
};
