<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->boolean('allow_postings')->default(false)->after('parent_id');
        });

        DB::statement(<<<'SQL'
            update financial_accounts
            set allow_postings = true
            where id in (select distinct parent_id from financial_accounts where parent_id is not null)
                and id in (select distinct financial_account_id from financial_postings)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn('allow_postings');
        });
    }
};
