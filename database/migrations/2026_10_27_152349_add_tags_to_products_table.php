<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tags an operator attaches to a product. They are stored as a
     * JSON list of short strings.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('tags')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
