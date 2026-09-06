<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_type');
            $table->foreignId('financial_institution_id')->nullable()->constrained('financial_institutions');
            $table->foreignId('parent_id')->nullable()->constrained('financial_accounts');
            $table->string('name');
            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->timestampsTz();

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
