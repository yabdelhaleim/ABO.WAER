<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for high-traffic queries.
 *
 * orders:
 *   - (status)           → DashboardService::getAttentionMetrics, getOrdersByStatus
 *   - (created_at)       → DashboardService::salesByBucket, cogsByBucket, returnsByBucket, totalsBetween
 *   - (status, created_at) → compound for the most common pattern: WHERE status != 'cancelled' AND created_at BETWEEN
 *   - (customer_id)      → DashboardService::getLatestOrders with('customer')
 *
 * variants:
 *   - (quantity)         → DashboardService::getAttentionMetrics low-stock count, getLowStockItems
 *   - (product_id, quantity) → ShopController sizes filter (whereHas variants with quantity > 0)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'orders_status_idx');
            $table->index('created_at', 'orders_created_at_idx');
            $table->index(['status', 'created_at'], 'orders_status_created_at_idx');
        });

        Schema::table('variants', function (Blueprint $table) {
            $table->index('quantity', 'variants_quantity_idx');
            $table->index(['product_id', 'quantity'], 'variants_product_quantity_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_archived', 'created_at'], 'products_archived_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_idx');
            $table->dropIndex('orders_created_at_idx');
            $table->dropIndex('orders_status_created_at_idx');
        });

        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex('variants_quantity_idx');
            $table->dropIndex('variants_product_quantity_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_archived_created_idx');
        });
    }
};
