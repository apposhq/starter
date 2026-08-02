<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Rules\PublicHttpsUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deliver one event to one endpoint, and keep trying for a while.
 *
 * A receiver being down is normal, not exceptional — deploys, restarts, brief outages. Retrying on a
 * widening schedule covers those without hammering something already struggling, and giving up after a
 * bounded number of attempts stops one dead endpoint from occupying a worker forever.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Roughly a day of coverage in total, front-loaded so a restart is retried in seconds.
     */
    public int $tries = 6;

    /**
     * Deleting an endpoint cascades to its deliveries, so a queued job can outlive its own row. That is
     * a retirement, not a failure — without this every in-flight delivery lands in failed_jobs and pages
     * whoever watches it.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Seconds between attempts. A receiver that is down for a deploy is caught by the first two; one
     * down for maintenance by the last.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 1800, 7200];
    }

    public function __construct(public WebhookDelivery $delivery) {}

    public function handle(): void
    {
        // SerializesModels reloads the row when the payload is unserialized, so this is already current.
        $delivery = $this->delivery;

        // Disabled after this was queued. A deleted endpoint cannot reach here at all — the cascade takes
        // the delivery row with it and $deleteWhenMissingModels retires the job.
        if (! $delivery->endpoint->isActive()) {
            return;
        }

        // Throws rather than returning false: a payload that cannot be encoded is a bug worth surfacing,
        // and signing the string "" or false would produce a delivery no receiver could verify.
        // Re-checked here, not only at registration: a hostname that resolved publicly when the endpoint
        // was saved can resolve to a private address by the time we deliver.
        if (! PublicHttpsUrl::isPublic($delivery->endpoint->url)) {
            $delivery->forceFill([
                'error' => 'The endpoint URL does not resolve to a public address.',
                'failed_at' => now(),
            ])->save();

            return;
        }

        $payload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();

        $delivery->increment('attempts');

        try {
            $response = Http::withHeaders([
                'webhook-id' => $delivery->event_id,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => $delivery->endpoint->sign($delivery->event_id, $timestamp, $payload),
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->connectTimeout(5)
                // A 302 is the way around every check above — the destination we vetted answers with a
                // Location we did not. Guzzle follows redirects by default; a webhook has no reason to.
                ->withOptions(['allow_redirects' => false])
                ->withBody($payload, 'application/json')
                ->post($delivery->endpoint->url);
        } catch (Throwable $e) {
            $delivery->forceFill(['error' => Str::limit($e->getMessage(), WebhookDelivery::MAX_ERROR_LENGTH)])->save();

            $this->giveUpOrRetry($delivery);

            return;
        }

        // Any 2xx is acceptance. Insisting on 200 would fail receivers that answer 202 or 204, which are
        // the honest answers for work that was queued rather than done.
        $delivery->forceFill([
            'response_status' => $response->status(),
            'response_body' => Str::limit((string) $response->body(), WebhookDelivery::MAX_RESPONSE_LENGTH),
            'error' => null,
            'delivered_at' => $response->successful() ? now() : null,
        ])->save();

        if (! $response->successful()) {
            $this->giveUpOrRetry($delivery);
        }
    }

    /**
     * Stamp the terminal state when the queue gives up on us from outside handle().
     *
     * A worker timeout, an OOM kill or a max-attempts overrun all bypass giveUpOrRetry, and without this
     * the row stays pending forever — the endpoint's failure count under-reports, and a webhook that
     * silently stopped arriving stays undiagnosable, which is the one thing this table exists to prevent.
     */
    public function failed(?Throwable $e): void
    {
        $this->delivery->forceFill([
            'error' => $e === null ? 'The delivery job failed.' : Str::limit($e->getMessage(), WebhookDelivery::MAX_ERROR_LENGTH),
            'failed_at' => now(),
        ])->saveQuietly();
    }

    /**
     * Record a terminal failure once the attempts are spent, otherwise let the queue retry.
     */
    protected function giveUpOrRetry(WebhookDelivery $delivery): void
    {
        if ($this->attempts() >= $this->tries) {
            $delivery->forceFill(['failed_at' => now()])->save();

            return;
        }

        // Indexed rather than defaulted: the guard above means attempts is always within the schedule.
        $this->release($this->backoff()[$this->attempts() - 1]);
    }
}
