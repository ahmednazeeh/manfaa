<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An UPLOADED icon for the storefront rail, alongside the `icon` name
     * added moments earlier. The two are a deliberate pair, resolved in this
     * order by every client:
     *
     *   icon_url (uploaded artwork)  →  icon (curated glyph name)  →  neutral
     *
     * The upload is what the platform actually wants — real category artwork
     * the way Rakuten draws it. The glyph name stays as the floor so the rail
     * is never a row of blank tiles: every seeded category already carries
     * one, so the storefront looks finished on day one and each upload
     * replaces a glyph rather than filling a hole.
     */
    public function up(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->string('icon_path')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->dropColumn('icon_path');
        });
    }
};
