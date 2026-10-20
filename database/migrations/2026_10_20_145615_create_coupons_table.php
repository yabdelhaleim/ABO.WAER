<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. SUMMER20
            $table->string('type')->default('percentage'); // percentage, fixed, shipping
            $table->decimal('value', 10, 2)->default(0.00); // 20% or 100 EGP
            $table->decimal('max_discount', 10, 2)->nullable(); // Cap for percentage
            $table->decimal('min_order_total', 10, 2)->nullable(); // Minimum cart subtotal
            // حدود الاستخدام
            $table->unsignedInteger('total_usage_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('usage_per_customer_limit')->nullable()->default(1);
            $table->unsignedInteger('used_count')->default(0);
            // الصلاحية
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
