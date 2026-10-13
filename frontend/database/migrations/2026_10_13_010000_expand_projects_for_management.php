<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->change();
            $table->text('api_key')->nullable()->change();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('nexus_api_key_id')->nullable();
            $table->uuid('pending_revoke_key_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('projects')->whereNull('owner_user_id')->exists()) {
            throw new RuntimeException('Cannot make project ownership required while unassigned projects exist.');
        }

        if (DB::table('projects')->whereNull('api_key')->exists()) {
            throw new RuntimeException('Cannot make project API keys required while revoked projects exist.');
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['description', 'metadata', 'nexus_api_key_id', 'pending_revoke_key_id']);
            $table->foreignId('owner_user_id')->nullable(false)->change();
            $table->text('api_key')->nullable(false)->change();
        });
    }
};
