<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_guide_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_guide_id')->constrained('size_guides')->cascadeOnDelete();
            $table->string('size_name');
            $table->decimal('chest', 5, 1);
            $table->decimal('length', 5, 1);
            $table->decimal('sleeve', 5, 1)->nullable();
            $table->decimal('shoulder', 5, 1)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('size_guide_rows');
    }
};
