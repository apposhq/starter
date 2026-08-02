<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt-tracked delivery of one event to one endpoint.
 *
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 */
class WebhookDelivery extends Model
{
    /**
     * Enough of a response to diagnose a failure, not enough for an endpoint returning an HTML error
     * page to fill the table.
     */
    public const MAX_RESPONSE_LENGTH = 2000;

    /**
     * The `error` column is varchar(255) and Str::limit appends an ellipsis to whatever it truncates,
     * so the limit has to leave room for it — 255 would produce 258 characters and fail the insert.
     */
    public const MAX_ERROR_LENGTH = 252;

    protected $fillable = [
        'webhook_endpoint_id',
        'event_id',
        'event_type',
        'payload',
        'attempts',
        'response_status',
        'response_body',
        'error',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function succeeded(): bool
    {
        return $this->delivered_at !== null;
    }
}
