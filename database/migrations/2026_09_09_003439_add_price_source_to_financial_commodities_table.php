<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_commodities', function (Blueprint $table) {
            $table->string('price_source', 16)->nullable();
            $table->string('price_symbol', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_commodities', function (Blueprint $table) {
            $table->dropColumn(['price_source', 'price_symbol']);
        });
    }
};
