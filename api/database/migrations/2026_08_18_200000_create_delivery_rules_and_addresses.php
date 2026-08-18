<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP3 — what a branch will deliver, and where to (§2.4, §2.5).
 *
 * The owner's case, which the shape has to express: a Malé shop wants a low
 * free-delivery minimum to Malé and a high one to Hulhumalé, and a
 * Hulhumalé shop wants the mirror image. That is not a merchant-level
 * setting — a chain with shops on both islands needs both sets of terms at
 * once, each cheap on its own island — so the rule belongs to the BRANCH.
 *
 * A row exists only for an island the branch serves. Adding a row IS "add a
 * delivery island"; deleting one is "stop serving there".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_delivery_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();

            // THE number the merchant sets per island (owner, 2026-08-18):
            // the order value at which delivery becomes free. Null means
            // this branch never gives free delivery to that island.
            $table->unsignedBigInteger('free_delivery_over_laari')->nullable();

            // Charged below that threshold. Zero is a legitimate answer —
            // a shop that always delivers free to its own island.
            $table->unsignedBigInteger('delivery_fee_laari')->default(0);

            // Optional FLOOR: below this the branch will not deliver there
            // at all. Null by default, so a branch only refuses small orders
            // if it deliberately decides to (§11.2).
            $table->unsignedBigInteger('order_minimum_laari')->nullable();

            // Minutes to THAT island — the "30–60 min" chip is per
            // destination, not per shop.
            $table->unsignedSmallInteger('eta_min')->nullable();
            $table->unsignedSmallInteger('eta_max')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'zone_id']);
            $table->index('zone_id');
        });

        DB::statement('
            ALTER TABLE branch_delivery_rules ADD CONSTRAINT delivery_eta_window_check
            CHECK (eta_min IS NULL OR eta_max IS NULL OR eta_max >= eta_min)
        ');

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('label')->default('Home');
            $table->string('recipient_name');
            $table->string('phone');

            // Resolved from the PIN, never typed. An island a customer types
            // and an island a courier drives to must not be able to differ.
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('island')->nullable();
            $table->string('area_magu')->nullable();
            $table->string('building');
            $table->string('apartment_floor')->nullable();
            $table->text('delivery_note')->nullable();

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });

        // Coordinates are a PAIR — the same rule branches have obeyed since
        // MR8. Half a coordinate places a delivery in the sea off Africa.
        DB::statement('
            ALTER TABLE customer_addresses ADD CONSTRAINT address_coordinate_pair_check
            CHECK ((lat IS NULL AND lng IS NULL) OR (lat IS NOT NULL AND lng IS NOT NULL))
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('branch_delivery_rules');
    }
};
