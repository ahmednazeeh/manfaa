<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovery surface flags (§10 apps/web): featured placement, a display
     * category, and whether the merchant sells online. Presentation-only —
     * nothing here ever participates in money computation.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('featured')->default(false);
            $table->string('category')->nullable();
            $table->boolean('is_online')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['featured', 'category', 'is_online']);
        });
    }
};
