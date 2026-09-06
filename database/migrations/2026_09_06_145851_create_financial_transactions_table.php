<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('financial_payee_id')->nullable()->constrained('financial_payees');
            $table->string('memo')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
