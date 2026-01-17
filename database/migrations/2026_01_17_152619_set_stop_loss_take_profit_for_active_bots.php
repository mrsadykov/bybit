<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Устанавливает значения по умолчанию для Stop-Loss и Take-Profit на активных ботов.
     */
    public function up(): void
    {
        // Устанавливаем значения по умолчанию для активных ботов, у которых еще не заданы SL/TP
        // Stop-Loss: 5.0% (продать при падении на 5%)
        // Take-Profit: 10.0% (продать при росте на 10%)
        
        DB::table('trading_bots')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('stop_loss_percent')
                      ->orWhereNull('take_profit_percent');
            })
            ->update([
                'stop_loss_percent' => DB::raw('COALESCE(stop_loss_percent, 5.0)'),
                'take_profit_percent' => DB::raw('COALESCE(take_profit_percent, 10.0)'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     * При откате миграции обнуляем значения только для активных ботов с дефолтными значениями.
     */
    public function down(): void
    {
        // Обнуляем значения для активных ботов при откате (только если равны дефолтным)
        DB::table('trading_bots')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('stop_loss_percent', 5.0)
                      ->orWhere('take_profit_percent', 10.0);
            })
            ->update([
                'stop_loss_percent' => null,
                'take_profit_percent' => null,
                'updated_at' => now(),
            ]);
    }
};
