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
            // Stop-Loss процент (например, -5.0 = продать при падении на 5%)
            $table->decimal('stop_loss_percent', 5, 2)->nullable()->after('position_size');
            // Take-Profit процент (например, 10.0 = продать при росте на 10%)
            $table->decimal('take_profit_percent', 5, 2)->nullable()->after('stop_loss_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->dropColumn(['stop_loss_percent', 'take_profit_percent']);
        });
    }
};
