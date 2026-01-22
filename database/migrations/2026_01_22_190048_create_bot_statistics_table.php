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
        Schema::create('bot_statistics', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            // Связь с ботом (nullable для общей статистики)
            $table->foreignId('trading_bot_id')
                ->nullable()
                ->constrained('trading_bots')
                ->cascadeOnDelete();

            // Период анализа
            $table->date('analysis_date')->index();
            $table->integer('days_period')->default(30); // За сколько дней анализировали

            // Базовые метрики
            $table->integer('total_trades')->default(0);
            $table->integer('winning_trades')->default(0);
            $table->integer('losing_trades')->default(0);
            $table->decimal('win_rate', 5, 2)->default(0); // Процент

            // PnL метрики
            $table->decimal('total_pnl', 16, 8)->default(0);
            $table->decimal('avg_pnl', 16, 8)->default(0);
            $table->decimal('avg_win', 16, 8)->default(0);
            $table->decimal('avg_loss', 16, 8)->default(0);

            // Дополнительные метрики
            $table->decimal('profit_factor', 8, 2)->default(0);
            $table->decimal('max_drawdown', 16, 8)->default(0);
            $table->decimal('best_trade', 16, 8)->default(0);
            $table->decimal('worst_trade', 16, 8)->default(0);
            $table->decimal('avg_hold_time_hours', 8, 2)->default(0);
            $table->decimal('trades_per_day', 8, 2)->default(0);

            // Индексы для быстрого поиска
            $table->index(['trading_bot_id', 'analysis_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_statistics');
    }
};
