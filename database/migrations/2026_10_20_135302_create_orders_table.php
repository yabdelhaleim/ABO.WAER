<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique(); // e.g. ABO-2026-1001
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Customer and recipient data
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            // Shipping and location data
            $table->foreignId('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->string('governorate_name'); // snapshot of the governorate name in case it changes
            $table->string('area');
            $table->text('address');
            $table->text('notes')->nullable();
            // Financial amounts
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_fee', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2);
            // Payment and shipping
            $table->string('payment_method')->default('cod'); // cod, card, etc.
            $table->string('payment_status')->default('pending');
            $table->string('status')->default('pending');
            $table->string('tracking_number')->nullable()->index();
            // On cancellation
            $table->foreignId('cancellation_reason_id')->nullable()->constrained('cancellation_reasons')->nullOnDelete();
            $table->text('cancellation_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
