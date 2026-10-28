<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bell's switches have to mean what they say.
     *
     * "Return request" promised a returns desk this shop does not
     * have; the event it now watches is a customer cancelling an
     * order, so the setting follows the event to its new name and
     * keeps the value the store chose.
     *
     * "Shipment failed" promised a courier callback that nothing in
     * this application can receive, so the setting goes away rather
     * than sit on the screen as a switch that can never fire.
     */
    public function up(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        // The key is unique, so the target has to make room first.
        DB::table('store_settings')->where('key', 'notifications.customerCancellation')->delete();

        DB::table('store_settings')
            ->where('key', 'notifications.returnRequest')
            ->update(['key' => 'notifications.customerCancellation']);

        DB::table('store_settings')->where('key', 'notifications.shipmentFailed')->delete();
    }

    /**
     * Put the old switch back the way it was.
     */
    public function down(): void
    {
        if (! Schema::hasTable('store_settings')) {
            return;
        }

        DB::table('store_settings')->where('key', 'notifications.returnRequest')->delete();

        DB::table('store_settings')
            ->where('key', 'notifications.customerCancellation')
            ->update(['key' => 'notifications.returnRequest']);
    }
};
