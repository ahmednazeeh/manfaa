<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Island zoning: the admin draws a polygon around an island and names it;
// every branch whose pin falls inside belongs to that zone. The polygon is
// island-scale (dozens of points), so jsonb + PHP ray-casting is plenty —
// no PostGIS dependency for a country of small, well-separated islands.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('name_dv', 100)->nullable();
            // Ordered ring of {lat, lng} points; the ring closes itself.
            $table->jsonb('polygon');
            $table->timestampsTz();
        });

        Schema::table('merchant_branches', function (Blueprint $table) {
            // Recomputed on every zone or coordinate write — reads stay a
            // plain integer comparison, geometry runs only at write time.
            $table->foreignId('zone_id')
                ->nullable()
                ->constrained('zones')
                ->nullOnDelete();
            $table->index('zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });
        Schema::dropIfExists('zones');
    }
};
