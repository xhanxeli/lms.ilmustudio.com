<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add indexes to sales table for frequently queried columns
        Schema::table('sales', function (Blueprint $table) {
            // Index for buyer_id queries (user purchases)
            if (!$this->indexExists('sales', 'sales_buyer_id_index')) {
                $table->index('buyer_id', 'sales_buyer_id_index');
            }
            
            // Index for seller_id queries (instructor sales)
            if (!$this->indexExists('sales', 'sales_seller_id_index')) {
                $table->index('seller_id', 'sales_seller_id_index');
            }
            
            // Composite index for buyer_id + type + refund_at (most common query pattern)
            if (!$this->indexExists('sales', 'sales_buyer_type_refund_index')) {
                $table->index(['buyer_id', 'type', 'refund_at'], 'sales_buyer_type_refund_index');
            }
            
            // Index for type queries
            if (!$this->indexExists('sales', 'sales_type_index')) {
                $table->index('type', 'sales_type_index');
            }
            
            // Index for refund_at (filtering non-refunded sales)
            if (!$this->indexExists('sales', 'sales_refund_at_index')) {
                $table->index('refund_at', 'sales_refund_at_index');
            }
            
            // Index for subscribe_id queries
            if (!$this->indexExists('sales', 'sales_subscribe_id_index')) {
                $table->index('subscribe_id', 'sales_subscribe_id_index');
            }
            
            // Composite index for subscription queries
            if (!$this->indexExists('sales', 'sales_buyer_subscribe_type_index')) {
                $table->index(['buyer_id', 'subscribe_id', 'type', 'refund_at'], 'sales_buyer_subscribe_type_index');
            }
        });

        // Add indexes to subscribe_uses table
        Schema::table('subscribe_uses', function (Blueprint $table) {
            // Index for user_id queries
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_user_id_index')) {
                $table->index('user_id', 'subscribe_uses_user_id_index');
            }
            
            // Index for subscribe_id queries
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_subscribe_id_index')) {
                $table->index('subscribe_id', 'subscribe_uses_subscribe_id_index');
            }
            
            // Index for active status queries
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_active_index')) {
                $table->index('active', 'subscribe_uses_active_index');
            }
            
            // Composite index for user_id + active + expired_at (most common query pattern)
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_user_active_expired_index')) {
                $table->index(['user_id', 'active', 'expired_at'], 'subscribe_uses_user_active_expired_index');
            }
            
            // Composite index for subscribe_id + active (subscription usage queries)
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_subscribe_active_index')) {
                $table->index(['subscribe_id', 'active'], 'subscribe_uses_subscribe_active_index');
            }
            
            // Index for sale_id queries
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_sale_id_index')) {
                $table->index('sale_id', 'subscribe_uses_sale_id_index');
            }
            
            // Index for webinar_id queries
            if (!$this->indexExists('subscribe_uses', 'subscribe_uses_webinar_id_index')) {
                $table->index('webinar_id', 'subscribe_uses_webinar_id_index');
            }
            
            // Index for bundle_id queries (if column exists)
            if (Schema::hasColumn('subscribe_uses', 'bundle_id')) {
                if (!$this->indexExists('subscribe_uses', 'subscribe_uses_bundle_id_index')) {
                    $table->index('bundle_id', 'subscribe_uses_bundle_id_index');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_buyer_id_index');
            $table->dropIndex('sales_seller_id_index');
            $table->dropIndex('sales_buyer_type_refund_index');
            $table->dropIndex('sales_type_index');
            $table->dropIndex('sales_refund_at_index');
            $table->dropIndex('sales_subscribe_id_index');
            $table->dropIndex('sales_buyer_subscribe_type_index');
        });

        Schema::table('subscribe_uses', function (Blueprint $table) {
            $table->dropIndex('subscribe_uses_user_id_index');
            $table->dropIndex('subscribe_uses_subscribe_id_index');
            $table->dropIndex('subscribe_uses_active_index');
            $table->dropIndex('subscribe_uses_user_active_expired_index');
            $table->dropIndex('subscribe_uses_subscribe_active_index');
            $table->dropIndex('subscribe_uses_sale_id_index');
            $table->dropIndex('subscribe_uses_webinar_id_index');
            if (Schema::hasColumn('subscribe_uses', 'bundle_id')) {
                $table->dropIndex('subscribe_uses_bundle_id_index');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists($table, $indexName)
    {
        try {
            $connection = Schema::getConnection();
            $databaseName = $connection->getDatabaseName();
            
            $result = $connection->select(
                "SELECT COUNT(*) as count FROM information_schema.statistics 
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$databaseName, $table, $indexName]
            );
            
            return isset($result[0]) && $result[0]->count > 0;
        } catch (\Exception $e) {
            // If query fails, assume index doesn't exist
            return false;
        }
    }
};
