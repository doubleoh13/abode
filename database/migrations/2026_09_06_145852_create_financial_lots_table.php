<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities');
            $table->date('acquired_at');
            $table->decimal('cost', 78, 25);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index('financial_commodity_id');
        });

        DB::statement('ALTER TABLE financial_lots ADD CONSTRAINT financial_lots_cost_nonnegative CHECK (cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_lots');
    }
};
