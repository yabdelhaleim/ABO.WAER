<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The indexes SQLite never creates on its own.
     *
     * A foreign key constraint does NOT give the referencing column an
     * index in SQLite (MySQL builds one implicitly, SQLite does not), so
     * every join that starts at one of these columns was a full table
     * scan. `order_items` had no index at all, which made the
     * orders → order_items join — the core of every order screen — scan
     * the whole table.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('variant_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id');
        });

        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->index('governorate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['variant_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
        });

        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropIndex(['governorate_id']);
        });
    }
};
