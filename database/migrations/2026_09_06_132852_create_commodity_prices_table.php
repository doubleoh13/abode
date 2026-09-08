<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_commodity_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_commodity_id')->constrained('financial_commodities')->cascadeOnDelete();
            $table->decimal('price', 78, 25);
            $table->timestampTz('priced_at');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['financial_commodity_id', 'priced_at']);
        });

        DB::statement('ALTER TABLE financial_commodity_prices ADD CONSTRAINT financial_commodity_prices_price_nonnegative CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_commodity_prices');
    }
};
