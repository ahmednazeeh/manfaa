<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Where the customer's profile picture lives on the private
            // `avatars` disk: customers/{id}/{uuid}.{ext}. Nullable — an
            // avatar is optional and most accounts will never set one. The
            // uuid filename doubles as the unguessable token the serving
            // URL carries (see App\Domain\Customers\CustomerAvatar).
            $table->string('avatar_path', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
