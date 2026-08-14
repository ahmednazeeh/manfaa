<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.1 credential issuance audit. Each api_credentials row is the durable
     * record of one issued vendor token: which Sanctum token it minted
     * (`personal_access_token_id` — deliberately NOT a foreign key, because
     * revocation deletes the personal_access_tokens row while the credential
     * row must keep the linkage for audit), which admin issued it, and which
     * admin revoked it.
     */
    public function up(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->foreignId('issued_by')->nullable()->constrained('admin_users');
            $table->foreignId('revoked_by')->nullable()->constrained('admin_users');
        });
    }

    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn('personal_access_token_id');
        });
    }
};
