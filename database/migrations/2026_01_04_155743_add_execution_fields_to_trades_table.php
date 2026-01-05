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
        Schema::table('trades', function (Blueprint $table) {
            // ID ордера на бирже Bybit
            $table->string('order_id')
                ->nullable()
                ->after('status')
                ->index();

            // Комиссия
            $table->decimal('fee', 16, 8)
                ->nullable()
                ->after('quantity');

            // Валюта комиссии (BTC / USDT)
            $table->string('fee_currency', 10)
                ->nullable()
                ->after('fee');

            // Время фактического исполнения ордера
            $table->timestamp('filled_at')
                ->nullable()
                ->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn([
                'order_id',
                'fee',
                'fee_currency',
                'filled_at',
            ]);
        });
    }
};
