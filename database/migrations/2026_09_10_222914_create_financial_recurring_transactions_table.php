<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_payee_id')->nullable()->constrained('financial_payees');
            $table->string('memo')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval');
            $table->date('starts_on');
            $table->date('next_due_on')->index();
            $table->date('ends_on')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE financial_recurring_transactions ADD CONSTRAINT financial_recurring_transactions_interval_positive CHECK (interval >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_recurring_transactions');
    }
};
