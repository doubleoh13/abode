<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_commodity_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commodity_id')->constrained('financial_commodities')->cascadeOnDelete();
            $table->decimal('price', 24, 12);
            $table->timestampTz('priced_at');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['commodity_id', 'priced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_commodity_prices');
    }
};
