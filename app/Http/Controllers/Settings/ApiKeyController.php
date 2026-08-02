<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    /**
     * List the team's API keys.
     *
     * The secret is never sent — only the masked form and the metadata needed to decide whether a key is
     * still wanted: who created it, when it was last used and from where.
     */
    public function index(Request $request, Team $team): Response
    {

        return Inertia::render('settings/api-keys', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'keys' => $team->apiKeys()->with('creator')->latest()->get()->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'masked' => $key->masked(),
                'mode' => $key->mode->value,
                'created_by' => $key->creator?->name,
                'created_at' => $key->created_at?->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'last_used_ip' => $key->last_used_ip,
                'active' => $key->isActive(),
            ]),
        ]);
    }

    /**
     * Mint a key and hand back the secret.
     *
     * The plaintext is flashed rather than stored, so it appears once on the next render and is then
     * gone. Only its hash was ever written.
     */
    public function store(Request $request, Team $team): RedirectResponse
    {

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(ApiKeyMode::class)],
        ]);

        $mode = ApiKeyMode::from($validated['mode']);

        [$secret, $hint] = ApiKey::mintSecret($mode);

        $team->apiKeys()->create([
            'name' => $validated['name'],
            'token' => hash('sha256', $secret),
            'abilities' => ['*'],
            'created_by' => $request->user()->id,
            'mode' => $mode,
            'last_four' => $hint,
        ]);

        Inertia::flash('secret', $secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key created. Copy it now — it will not be shown again.')]);

        return to_route('api-keys.index', ['team' => $team->slug]);
    }

    /**
     * Revoke a key, optionally after a grace period.
     *
     * The row is kept rather than deleted: a `last_used_at` after the revocation is how a team learns
     * something is still sending the old key.
     */
    public function destroy(Request $request, Team $team, ApiKey $apiKey): RedirectResponse
    {

        // Bounded: an unchecked value overflows the timestamp column, and the 500 that follows leaves the
        // key someone was trying to kill still authenticating.
        $graceHours = (int) ($request->validate([
            'grace_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
        ])['grace_hours'] ?? 0);

        $apiKey->revoke($graceHours > 0 ? now()->addHours($graceHours) : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $graceHours > 0
            ? __('API key will stop working in :hours hours.', ['hours' => $graceHours])
            : __('API key revoked.')]);

        return to_route('api-keys.index', ['team' => $team->slug]);
    }
}
