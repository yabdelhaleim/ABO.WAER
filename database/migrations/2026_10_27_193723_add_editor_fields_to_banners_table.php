<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin banners screen edits more than the table could hold:
     * a placement, a schedule window, alt text, the look controls and
     * the link target were all demo-only in the browser. These columns
     * give every one of those controls a place in the database, so the
     * screen stores what it shows instead of pretending.
     */
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // Where the banner shows up: hero / promo / announcement.
            $table->string('placement')->default('hero')->after('sort_order');

            // Optional schedule window. A banner whose window has not
            // opened is "scheduled"; one whose window has closed is
            // "expired". Null on either side means "no bound".
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            // Alt text for the banner image, per language.
            $table->string('alt')->nullable();
            $table->string('alt_ar')->nullable();

            // A second, narrower image for phones.
            $table->string('mobile_image_path')->nullable();

            // Look controls.
            $table->string('text_color')->default('light');
            $table->string('text_position')->default('left');
            $table->string('overlay')->default('none');

            // Link target: "none" | "category" | "product" | "url".
            // link_target keeps the slug the admin picked; button_url
            // keeps the resolved link the storefront follows.
            $table->string('link_type')->default('url');
            $table->string('link_target')->nullable();

            // An announcement banner has no picture, so the image is no
            // longer mandatory. The request layer requires one for the
            // placements that do show a picture.
            $table->string('image_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'placement',
                'starts_at',
                'ends_at',
                'alt',
                'alt_ar',
                'mobile_image_path',
                'text_color',
                'text_position',
                'overlay',
                'link_type',
                'link_target',
            ]);

            $table->string('image_path')->nullable()->change();
        });
    }
};
