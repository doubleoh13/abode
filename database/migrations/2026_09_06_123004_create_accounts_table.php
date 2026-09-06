<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_type');
            $table->foreignId('institution_id')->nullable()->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->string('name');
            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->timestamps();

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
