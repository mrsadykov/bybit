<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('futures_trades', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('futures_bot_id')->constrained('futures_bots')->cascadeOnDelete();
            $table->string('side', 4); // BUY / SELL
            $table->string('symbol', 20);
            $table->decimal('price', 20, 8);
            $table->decimal('quantity', 20, 8); // в контрактах или в базовой валюте по API
            $table->decimal('fee', 20, 8)->default(0);
            $table->string('fee_currency', 10)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('filled_at')->nullable();
            $table->string('order_id', 64)->nullable();
            $table->json('exchange_response')->nullable();
            $table->timestamp('closed_at')->nullable(); // когда позиция закрыта (для пары open/close)
            $table->decimal('realized_pnl', 20, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('futures_trades');
    }
};
