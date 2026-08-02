<?php

namespace App\Support;

use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Send an event to every endpoint a team has that asked for it.
 *
 * One call site for emitting events, so a feature that wants to notify customers does not have to know
 * how endpoints are stored, which of them subscribed, or how delivery is retried.
 */
class Webhooks
{
    /**
     * Queue an event for delivery.
     *
     * The event id is generated once, here, and reused for every retry — a receiver that already handled
     * an id can then skip it, which is the same guarantee `Idempotency-Key` gives in the other direction.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     */
    public static function send(Team $team, string $type, array $payload): Collection
    {
        $eventId = (string) Str::ulid();

        return $team->webhooks()
            ->active()
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($type))
            ->map(function (WebhookEndpoint $endpoint) use ($eventId, $type, $payload): WebhookDelivery {
                $delivery = $endpoint->deliveries()->create([
                    'event_id' => $eventId,
                    'event_type' => $type,
                    'payload' => [
                        'id' => $eventId,
                        'type' => $type,
                        'created_at' => now()->toIso8601String(),
                        'data' => $payload,
                    ],
                ]);

                DeliverWebhook::dispatch($delivery);

                return $delivery;
            })
            ->values();
    }
}
