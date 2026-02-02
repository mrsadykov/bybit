<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_decision_logs', function (Blueprint $table) {
            $table->id();
            $table->string('bot_type', 20)->index(); // spot, futures, btc_quote
            $table->unsignedBigInteger('bot_id')->index();
            $table->string('symbol', 32)->index();
            $table->string('signal', 10)->index(); // HOLD, BUY, SELL, SKIP
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('rsi', 10, 4)->nullable();
            $table->decimal('ema', 20, 8)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('bot_decision_logs', function (Blueprint $table) {
            $table->index(['bot_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_decision_logs');
    }
};
