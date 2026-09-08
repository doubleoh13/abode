<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')->constrained('financial_transactions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('status')->nullable();
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities');
            $table->foreignId('financial_lot_id')->nullable()->constrained('financial_lots');
            $table->decimal('amount', 78, 25);
            $table->string('memo')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['financial_transaction_id', 'position']);
            $table->index('financial_account_id');
            $table->index('financial_lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_postings');
    }
};
