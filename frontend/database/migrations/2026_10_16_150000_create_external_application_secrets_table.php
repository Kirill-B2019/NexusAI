<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_application_secrets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('key_id')->unique();
            $table->string('application_name', 100);
            $table->string('secret_name', 512)->unique();
            $table->string('secret_arn', 512);
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_application_secrets');
    }
};
