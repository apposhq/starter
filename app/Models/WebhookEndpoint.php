<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A URL a team wants notified, and the secret its deliveries are signed with.
 *
 * Signing follows the Standard Webhooks specification rather than a scheme invented here, so a customer
 * can verify deliveries with an off-the-shelf library instead of reading our documentation and writing
 * HMAC code by hand — which is where verification usually goes wrong, or gets skipped.
 *
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property ?CarbonInterface $disabled_at
 * @property-read ?int $failed_deliveries_count only present when the query counted them
 */
class WebhookEndpoint extends Model
{
    /**
     * Subscribing to this instead of a list means "everything", including events added later.
     */
    public const ALL_EVENTS = '*';

    protected $fillable = [
        'team_id',
        'created_by',
        'url',
        'description',
        'secret',
        'events',
        'disabled_at',
    ];

    /**
     * The secret signs deliveries, so it must never reach a payload, a log or an exception report.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Mint a signing secret.
     *
     * Base64 behind a `whsec_` prefix, which is the shape Standard Webhooks defines — a verifying library
     * strips the prefix and decodes the rest, so anything else silently fails to verify.
     */
    public static function mintSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(32));
    }

    /**
     * Sign one delivery.
     *
     * The signed content is `id.timestamp.payload`. The id and timestamp are inside the signature rather
     * than beside it so neither can be altered in flight — which is what makes the timestamp usable as
     * replay protection at all.
     */
    public function sign(string $eventId, int $timestamp, string $payload): string
    {
        $signature = hash_hmac(
            'sha256',
            "{$eventId}.{$timestamp}.{$payload}",
            base64_decode(Str::after($this->secret, 'whsec_')),
            binary: true,
        );

        return 'v1,'.base64_encode($signature);
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * Whether this endpoint asked to hear about the given event.
     */
    public function subscribesTo(string $event): bool
    {
        return in_array(self::ALL_EVENTS, $this->events, true)
            || in_array($event, $this->events, true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }
}
