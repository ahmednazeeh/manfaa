<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_tier_schedules', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('effective_from')->index();
            $table->jsonb('tiers');
            $table->foreignId('created_by')->nullable()->constrained('admin_users');
            $table->timestampTz('created_at');
            // Append-only effective dating: no updated_at, rows are never
            // updated or deleted. The schedule active at instant T is the
            // latest row with effective_from <= T.
        });

        // Seed the §4 hardcoded tier table with effective_from in the far
        // past, so every historical instant resolves to exactly the terms the
        // static FeeTier map produced — behaviour is identical until an admin
        // publishes a future-dated schedule.
        DB::table('fee_tier_schedules')->insert([
            'effective_from' => '1970-01-01 00:00:00+00',
            'tiers' => json_encode([
                ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 25],
                ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 50],
                ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 75],
                ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 100],
            ]),
            'created_by' => null,
            'created_at' => now('UTC'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_tier_schedules');
    }
};
