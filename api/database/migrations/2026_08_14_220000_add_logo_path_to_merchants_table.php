<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant logo (Task #21 branding): a path on the public disk, rendered
     * as an absolute /storage/ URL on discovery cards and the store page.
     * Presentation-only — never participates in money computation.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
