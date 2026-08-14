<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // §5: timestamps are timestamptz everywhere. This table came from the
        // Sanctum vendor stub with plain timestamp columns; the app writes
        // UTC, so the conversion pins the existing instants to UTC.
        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
                ALTER COLUMN last_used_at TYPE timestamptz USING last_used_at AT TIME ZONE 'UTC',
                ALTER COLUMN expires_at TYPE timestamptz USING expires_at AT TIME ZONE 'UTC',
                ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE 'UTC',
                ALTER COLUMN updated_at TYPE timestamptz USING updated_at AT TIME ZONE 'UTC'
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE personal_access_tokens
                ALTER COLUMN last_used_at TYPE timestamp USING last_used_at AT TIME ZONE 'UTC',
                ALTER COLUMN expires_at TYPE timestamp USING expires_at AT TIME ZONE 'UTC',
                ALTER COLUMN created_at TYPE timestamp USING created_at AT TIME ZONE 'UTC',
                ALTER COLUMN updated_at TYPE timestamp USING updated_at AT TIME ZONE 'UTC'
            SQL);
    }
};
