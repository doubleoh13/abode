<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_balance_assertions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities');
            $table->date('asserted_at');
            $table->decimal('balance', 78, 25);
            $table->string('memo', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['financial_account_id', 'financial_commodity_id', 'asserted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_balance_assertions');
    }
};
