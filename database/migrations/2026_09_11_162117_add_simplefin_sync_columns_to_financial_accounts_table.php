<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->timestampTz('simplefin_synced_at')->nullable()->after('simplefin_account_id');
            $table->decimal('simplefin_balance', 78, 25)->nullable()->after('simplefin_synced_at');
            $table->date('simplefin_balance_date')->nullable()->after('simplefin_balance');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn(['simplefin_synced_at', 'simplefin_balance', 'simplefin_balance_date']);
        });
    }
};
