<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('exchange_accounts', function (Blueprint $table) {
            $table->boolean('is_testnet')
                ->default(false)
                ->after('api_secret')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_accounts', function (Blueprint $table) {
            $table->dropIndex(['is_testnet']);
            $table->dropColumn('is_testnet');
        });
    }
};

