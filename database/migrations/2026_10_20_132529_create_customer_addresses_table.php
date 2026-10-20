<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('governorate_id')->constrained('governorates')->cascadeOnDelete();
            // Recipient on the parcel — this is the checkout form's contact
            // name, not the account owner's name: a customer routinely ships
            // to a friend or to a different branch.
            $table->string('name');
            $table->string('phone', 20);
            $table->string('area');
            $table->text('address');
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            // Every read of this table is scoped to one customer and wants the
            // default row first, so the pair is indexed together rather than
            // giving customer_id its own redundant index.
            $table->index(['customer_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
