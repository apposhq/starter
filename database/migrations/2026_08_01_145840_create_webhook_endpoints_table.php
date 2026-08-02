<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a team wants to be told when something happens.
     *
     * Owned by the team for the same reason an API key is: an integration outlives whoever configured it.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Wide enough for what validation accepts: a shorter column turns a legitimate long URL
            // into a 500 at INSERT rather than a 422 naming the field.
            $table->string('url', 2048);
            $table->string('description')->nullable();

            // The signing secret, shown once at creation. Kept in plaintext because signing needs the
            // secret itself — unlike an API key, where only a comparison is ever required.
            $table->string('secret');

            // Which events this endpoint wants. A wildcard entry means all of them, so a team that just
            // wants everything does not have to keep this list in step with the platform.
            $table->json('events');

            // Disabled rather than deleted, so a URL that started failing can be turned back on without
            // losing its history — and so the delivery rows keep pointing at something.
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
