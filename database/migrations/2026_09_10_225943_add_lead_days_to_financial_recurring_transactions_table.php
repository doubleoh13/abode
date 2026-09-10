<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_recurring_transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_days')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('financial_recurring_transactions', function (Blueprint $table) {
            $table->dropColumn('lead_days');
        });
    }
};
