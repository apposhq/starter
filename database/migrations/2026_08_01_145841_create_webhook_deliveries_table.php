<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every attempt to tell an endpoint something, and how it went.
     *
     * A webhook that silently stopped arriving is the hardest kind of integration bug to find, because
     * neither side has evidence. This table is that evidence: what was sent, when, and what came back.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();

            // The `webhook-id` the receiver sees. Stable across retries by design: it is what lets a
            // receiver recognise a redelivery and skip work it already did.
            $table->ulid('event_id')->index();

            $table->string('event_type')->index();
            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();

            // Truncated on write. A body is for diagnosis, and an endpoint returning a megabyte of HTML
            // should not be able to fill this table with it.
            $table->text('response_body')->nullable();
            $table->string('error')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'created_at']);

            // Retention is driven off this, so it has to be indexed independently of the endpoint.
            $table->index('created_at');
        });

        // Raw, because Blueprint has no partial index: the settings page counts failures per endpoint,
        // and an unfiltered index would still scan every delivery the endpoint ever had. Restricting the
        // index to the rows the query actually wants keeps that count proportional to failures rather
        // than to total traffic — which is the whole difference for a busy customer.
        DB::statement(
            'create index webhook_deliveries_failed_idx on webhook_deliveries (webhook_endpoint_id) where failed_at is not null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
