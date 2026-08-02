<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One use of an `Idempotency-Key`, and the response it produced.
 *
 * @property string $key
 * @property string $fingerprint
 * @property ?int $response_status
 * @property ?array<string, mixed> $response_body
 * @property ?CarbonInterface $completed_at
 * @property CarbonInterface $expires_at
 */
class ApiIdempotencyKey extends Model
{
    protected $fillable = [
        'team_id',
        'key',
        'fingerprint',
        'response_status',
        'response_body',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Identify a request by what it would do, not by when it was sent.
     *
     * Method, path and body — the three things that decide the effect. The query string is part of the
     * path here because it changes the outcome of a request just as the body does.
     */
    public static function fingerprint(Request $request): string
    {
        // getContent() is empty for multipart/form-data — PHP consumes that body into $_POST — so a raw
        // read alone would hash two genuinely different uploads to the same value and replay one for the
        // other. The parsed input covers what the raw body does not.
        $input = $request->all();
        ksort($input);

        return hash('sha256', implode('|', [
            $request->getMethod(),
            $request->getRequestUri(),
            $request->getContent(),
            json_encode($input),
        ]));
    }

    /**
     * Whether the original request finished and its answer was recorded.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
