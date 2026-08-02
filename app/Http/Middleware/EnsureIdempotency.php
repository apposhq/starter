<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\Team;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Make a retried write safe to send twice.
 *
 * A client whose request times out cannot tell whether the server processed it. Retrying risks doing the
 * work twice; not retrying risks losing work that never happened. `Idempotency-Key` resolves it: the
 * customer names the attempt, and a repeat of that name replays the first answer rather than acting again.
 *
 * Follows draft-ietf-httpapi-idempotency-key-header, which fixes the three failure answers — 400 for a
 * key that cannot be used, 422 for a key reused against a different request, 409 for a retry that arrives
 * while the original is still running.
 *
 * Only non-idempotent methods are covered. GET and DELETE already promise that repeating them changes
 * nothing, so recording them would add a write to every read for no gain. The header is optional: a
 * client that does not send one gets today's behaviour, and one that does gets the guarantee.
 */
class EnsureIdempotency
{
    /**
     * Methods whose effect is not already guaranteed to survive being repeated.
     *
     * PUT is here despite HTTP defining it idempotent, because `Route::apiResource` maps update to
     * `PUT|PATCH` and this application's update actions take partial payloads — so the guarantee the
     * verb implies is not one the handlers actually make. Honouring the header is also the only honest
     * option: silently ignoring one a client deliberately sent is worse than not offering the feature.
     */
    protected const GUARDED_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * Long enough to outlast a client's own retry schedule, short enough that reusing a key next week
     * means nothing. The draft requires an expiry policy and leaves the duration to the API.
     */
    protected const RETENTION_HOURS = 24;

    /**
     * How long a claim may stay unfinished before it is treated as abandoned.
     *
     * A claim is released by the process that took it, so a worker killed mid-request — a deploy, an OOM,
     * a request timeout — leaves one behind that nothing will ever complete. Without a takeover window
     * every retry of that key answers 409 until the retention sweep, locking a customer out of an
     * operation for a day. Comfortably longer than any request this application should serve.
     */
    protected const CLAIM_TIMEOUT_MINUTES = 5;

    /**
     * Response fields that must not be stored for replay.
     *
     * A one-time secret is shown once by definition. Recording the response verbatim would put it in a
     * second table and hand it back on every retry for the whole retention window, which is exactly the
     * property the endpoint promises it does not have.
     */
    protected const REDACTED_FIELDS = ['secret'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $team = $request->attributes->get('api_team');

        if (blank($key) || ! $team instanceof Team) {
            return $next($request);
        }

        // A key sent on a method this does not cover is a misunderstanding worth surfacing. GET and
        // DELETE already promise that repeating them changes nothing, so recording them would add a
        // write to every read — but answering 200 as though the key did something would be a lie.
        if (! in_array($request->getMethod(), self::GUARDED_METHODS, true)) {
            throw new BadRequestHttpException(sprintf(
                'Idempotency-Key is not supported on %s. It applies to %s, which are the methods that are not already repeatable.',
                $request->getMethod(),
                implode(', ', self::GUARDED_METHODS),
            ));
        }

        if (mb_strlen($key) > 255) {
            throw new BadRequestHttpException('Idempotency-Key must be 255 characters or fewer.');
        }

        $claim = $this->claim($team, $key, ApiIdempotencyKey::fingerprint($request));

        if ($claim instanceof Response) {
            return $claim;
        }

        $response = $next($request);

        // Only a settled outcome is worth replaying. Pinning a 500 to the key would make every retry
        // reproduce a transient failure, which is the opposite of the point — so the claim is released
        // and the next attempt starts clean.
        if ($response->getStatusCode() >= 500) {
            $claim->delete();

            return $response;
        }

        $claim->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_body' => $this->decode($response),
            'completed_at' => now(),
        ])->save();

        return $response;
    }

    /**
     * Take ownership of a key, or produce the answer it already has.
     *
     * Claiming is an insert against a unique index, so the database decides who is first. Reading before
     * writing would let two concurrent retries both conclude they were.
     */
    protected function claim(Team $team, string $key, string $fingerprint): ApiIdempotencyKey|Response
    {
        try {
            return $this->record($team, $key, $fingerprint);
        } catch (UniqueConstraintViolationException) {
            // Someone holds it. Which of the three cases it is depends on what they recorded.
        }

        $existing = ApiIdempotencyKey::query()
            ->where('team_id', $team->id)
            ->where('key', $key)
            ->first();

        // Pruned between the failed insert and this read, or expired and reusable. Either way the record
        // it would have replayed no longer applies, so the key is free again.
        if ($existing === null || $existing->expires_at->isPast()) {
            $existing?->delete();

            try {
                return $this->record($team, $key, $fingerprint);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictHttpException('This Idempotency-Key is in use. Retry shortly.');
            }
        }

        // Checked before the fingerprint: an abandoned claim is free whatever it was originally for, and
        // whoever held it is gone. Comparing fingerprints first would answer 422 for a key that is
        // actually available.
        if (! $existing->isCompleted() && $existing->created_at?->addMinutes(self::CLAIM_TIMEOUT_MINUTES)->isPast()) {
            $existing->delete();

            try {
                return $this->record($team, $key, $fingerprint);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictHttpException('This Idempotency-Key is in use. Retry shortly.');
            }
        }

        if ($existing->fingerprint !== $fingerprint) {
            throw new UnprocessableEntityHttpException(
                'This Idempotency-Key was already used for a different request. Use a new key.'
            );
        }

        if (! $existing->isCompleted()) {
            throw new ConflictHttpException(
                'A request with this Idempotency-Key is still in progress. Retry once it has finished.'
            );
        }

        return new JsonResponse($existing->response_body, $existing->response_status, [
            // Not in the draft, which is silent on marking replays. Added because a client otherwise
            // cannot tell "created" from "created earlier, and you are seeing it again".
            'Idempotent-Replayed' => 'true',
        ]);
    }

    /**
     * Insert the claim, isolated so its failure stays local.
     *
     * This insert is meant to be able to fail — losing the race is how a retry is detected. Postgres
     * aborts an entire transaction on any failed statement, so an unwrapped insert inside one would take
     * down whatever else that transaction was doing. Running it as its own transaction makes the failure
     * roll back to a savepoint when there is an enclosing one, and stand alone when there is not.
     */
    protected function record(Team $team, string $key, string $fingerprint): ApiIdempotencyKey
    {
        return DB::transaction(fn (): ApiIdempotencyKey => ApiIdempotencyKey::create([
            'team_id' => $team->id,
            'key' => $key,
            'fingerprint' => $fingerprint,
            'expires_at' => now()->addHours(self::RETENTION_HOURS),
        ]));
    }

    /**
     * The response body as data, so a replay reproduces it rather than a string of it.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(Response $response): ?array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $this->redact($decoded) : null;
    }

    /**
     * Strip one-time secrets out of anything we are about to store.
     *
     * Recursive because the field sits inside the resource's `data` envelope, and a nested resource
     * would bury it deeper still.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    protected function redact(array $body): array
    {
        foreach ($body as $field => $value) {
            if (in_array($field, self::REDACTED_FIELDS, true)) {
                unset($body[$field]);
            } elseif (is_array($value)) {
                $body[$field] = $this->redact($value);
            }
        }

        return $body;
    }
}
