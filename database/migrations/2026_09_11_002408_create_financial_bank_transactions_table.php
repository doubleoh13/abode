<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->string('source', 32);
            $table->string('external_id', 191);
            $table->date('posted_on');
            $table->date('transacted_on')->nullable();
            $table->boolean('pending');
            $table->decimal('amount', 78, 25);
            $table->string('currency', 16);
            $table->string('description')->nullable();
            $table->string('payee')->nullable();
            $table->string('memo')->nullable();
            $table->jsonb('payload');
            $table->foreignId('financial_posting_id')->nullable()->constrained('financial_postings')->nullOnDelete();
            $table->timestampTz('ignored_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source', 'external_id']);
            $table->index(['financial_account_id', 'posted_on']);
            $table->index('financial_posting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_bank_transactions');
    }
};
