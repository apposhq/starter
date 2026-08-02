<?php

namespace App\Models;

use App\Enums\ApiKeyMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API key a customer uses to call the platform.
 *
 * Sanctum's token, with the pieces a public API needs on top. The `tokenable` is the {@see Team}, never
 * the person who created it: a key is a customer's integration, so it has to outlive whoever clicked the
 * button. `created_by` records that person for the audit trail and goes null if they are deleted.
 *
 * The secret is never stored. Only its SHA-256 hash is, which is what Sanctum matches against — and
 * because the plaintext carries no `|`, Sanctum's own lookup hashes the whole string and finds it.
 *
 * @property ApiKeyMode $mode
 * @property ?CarbonInterface $revoked_at
 * @property ?string $last_four
 */
class ApiKey extends PersonalAccessToken
{
    /**
     * Sanctum's table, extended by an application migration rather than replaced.
     */
    protected $table = 'personal_access_tokens';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'created_by',
        'mode',
        'last_four',
    ];

    /**
     * The characters shown after the prefix so a key is recognisable in a list.
     */
    public const HINT_LENGTH = 4;

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'mode' => ApiKeyMode::class,
        ];
    }

    /**
     * The member who created the key, if that account still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mint a secret for the given mode.
     *
     * The shape is `sk_<mode>_<random><checksum>`, which follows what GitHub adopted and Stripe uses: a
     * prefix makes a leaked key identifiable by a secret scanner, and the trailing CRC32 lets a scanner
     * reject look-alikes without querying anything. Base62 keeps the whole thing copyable and
     * double-click selectable, which base64's `+/=` are not.
     *
     * @return array{0: string, 1: string} the plaintext secret and its last visible characters
     */
    public static function mintSecret(ApiKeyMode $mode): array
    {
        // 43 base62 characters is ~256 bits, comfortably past the point where guessing is the
        // attack anyone would choose.
        $random = Str::random(43);

        $body = $random.static::checksum($random);

        return ["sk_{$mode->value}_{$body}", substr($body, -self::HINT_LENGTH)];
    }

    /**
     * A base62 CRC32 of the random portion, so a malformed key can be rejected before any lookup.
     */
    public static function checksum(string $random): string
    {
        return substr(base_convert((string) crc32($random), 10, 36), 0, 6);
    }

    /**
     * Whether this key still authenticates.
     *
     * Revocation is a timestamp rather than a delete, so a key that is still being sent after it was
     * rotated shows up in `last_used_at` instead of vanishing silently.
     */
    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null && $this->revoked_at->isPast();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Revoke after a grace period, so a customer can deploy the replacement without an outage.
     *
     * Passing no grace revokes immediately, which is what you want for a leaked key.
     */
    public function revoke(?CarbonInterface $at = null): void
    {
        $at ??= now();

        // Never later than an existing revocation. Re-revoking with a grace period would otherwise push
        // the timestamp into the future and bring a key that was already dead back to life — which is
        // the opposite of what someone revoking a leaked key a second time is asking for.
        if ($this->revoked_at !== null && $this->revoked_at->lessThan($at)) {
            return;
        }

        $this->forceFill(['revoked_at' => $at])->save();
    }

    /**
     * Keys that can still be used right now.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The masked form shown wherever the secret itself must not appear.
     */
    public function masked(): string
    {
        return sprintf('sk_%s_%s%s', $this->mode->value, str_repeat('•', 8), $this->last_four);
    }
}
