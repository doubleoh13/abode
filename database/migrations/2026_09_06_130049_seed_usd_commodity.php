<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('commodities')->insert([
            'code' => 'USD',
            'name' => 'US Dollar',
            'kind' => 'currency',
            'precision' => 2,
            'symbol' => '$',
            'symbol_placement' => 'prefix',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('commodities')->where('code', 'USD')->delete();
    }
};
