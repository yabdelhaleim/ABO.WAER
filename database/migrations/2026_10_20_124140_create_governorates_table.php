<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            // A null fee / time means the governorate inherits its zone values.
            $table->decimal('fee', 10, 2)->nullable();
            $table->unsignedSmallInteger('time_from')->nullable();
            $table->unsignedSmallInteger('time_to')->nullable();
            $table->boolean('allows_cod')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governorates');
    }
};
