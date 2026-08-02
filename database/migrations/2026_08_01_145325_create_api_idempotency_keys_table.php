<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record what an idempotency key was used for, and what it answered.
     *
     * A customer retrying a POST after a timeout has no way to know whether the first attempt landed.
     * Replaying the recorded response is the only answer that is both safe and truthful — creating a
     * second resource is wrong, and so is reporting a failure for something that already succeeded.
     */
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();

            // Scoped to the team rather than to the key that happened to be used: rotating a key mid-retry
            // must not lose the record of what already ran, and one customer's chosen value must never
            // collide with another's.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('key');

            // Method, path and body, hashed. A key replayed against a different request is a client bug —
            // usually a constant that was meant to be generated — and answering it with the first
            // response would be worse than refusing, so the fingerprint is what makes that detectable.
            $table->string('fingerprint', 64);

            // Null until the request finishes. A second request arriving while these are still null is a
            // genuine concurrent retry, the one case that cannot be answered yet.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamps();

            // The lock. Claiming a key is an insert, so two concurrent retries cannot both believe they
            // are first — the database rejects the second rather than the application racing itself.
            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
