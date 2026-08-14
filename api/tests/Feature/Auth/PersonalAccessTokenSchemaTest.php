<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('stores every personal_access_tokens timestamp as timestamptz', function () {
    // §5: timestamps are timestamptz everywhere. The Sanctum vendor stub
    // shipped plain timestamp columns; the migration converts them.
    $types = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'personal_access_tokens')
        ->whereIn('column_name', ['last_used_at', 'expires_at', 'created_at', 'updated_at'])
        ->pluck('data_type', 'column_name');

    expect($types)->toHaveCount(4);

    foreach (['last_used_at', 'expires_at', 'created_at', 'updated_at'] as $column) {
        expect($types[$column])->toBe('timestamp with time zone');
    }
});
