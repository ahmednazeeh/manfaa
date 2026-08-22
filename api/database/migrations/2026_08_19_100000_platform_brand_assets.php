<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's replaceable brand marks (App\Domain\Platform\BrandAsset).
 *
 * One row per slot, and `slot` is unique because a slot IS the identity —
 * uploading again replaces rather than accumulates. A row's absence means
 * "the packaged default", which is why nothing is seeded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_brand_assets', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 32)->unique();
            $table->string('path');
            // What the superadmin's file was called. Kept only so the admin
            // screen can say which file is in place — never used to build a
            // path or a response header.
            $table->string('original_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_brand_assets');
    }
};
