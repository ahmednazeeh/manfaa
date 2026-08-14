<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Superadmin-curated store categories (§1 decision 2026-08-15): stores
     * pick from this list only — no free text. Distinct from the per-store
     * PRODUCT categories of Task #25. merchants.category keeps holding the
     * slug string; app-level validation constrains it to an ACTIVE row here.
     *
     * name_dv values are machine-draft Thaana, flagged for native review
     * (PLAN operational to-dos).
     */
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name_en', 120);
            $table->string('name_dv', 120)->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        $now = now('UTC');

        $seed = [
            ['grocery', 'Grocery', 'ގުރޮސަރީ'],
            ['restaurant', 'Restaurant', 'ރެސްޓޯރަންޓް'],
            ['cafe', 'Café', 'ކެފޭ'],
            ['fashion', 'Fashion', 'ފެޝަން'],
            ['electronics', 'Electronics', 'އިލެކްޓްރޯނިކްސް'],
            ['pharmacy', 'Pharmacy', 'ބޭސްފިހާރަ'],
            ['beauty', 'Beauty', 'ބިއުޓީ'],
            ['services', 'Services', 'ޚިދުމަތްތައް'],
            ['other', 'Other', 'އެހެނިހެން'],
        ];

        DB::table('store_categories')->insert(array_map(
            fn (array $row, int $i): array => [
                'slug' => $row[0],
                'name_en' => $row[1],
                'name_dv' => $row[2],
                'sort' => ($i + 1) * 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $seed,
            array_keys($seed),
        ));

        // Map pre-curation free-text categories onto the seeded slugs (the
        // live 'Test Store' carries 'Grocery'). Anything that lowercases to
        // a seeded slug becomes that slug; unknown values are left alone —
        // the wizard/profile validation will require a re-pick on next edit.
        DB::statement(<<<'SQL'
            UPDATE merchants
            SET category = lower(category)
            WHERE category IS NOT NULL
              AND lower(category) IN (SELECT slug FROM store_categories)
              AND category <> lower(category)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_categories');
    }
};
