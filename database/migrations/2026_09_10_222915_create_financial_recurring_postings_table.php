<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_recurring_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_recurring_transaction_id')->constrained('financial_recurring_transactions', indexName: 'financial_recurring_postings_schedule_foreign')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('status', 16)->nullable();
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities');
            $table->decimal('amount', 78, 25);
            $table->string('memo')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['financial_recurring_transaction_id', 'position'], 'financial_recurring_postings_schedule_position_unique');
            $table->index('financial_account_id');
        });

        DB::statement('ALTER TABLE financial_recurring_postings ADD CONSTRAINT financial_recurring_postings_amount_nonzero CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_recurring_postings');
    }
};
