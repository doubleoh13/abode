<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities');
            $table->date('acquired_at');
            $table->decimal('cost', 78, 0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_lots');
    }
};
