<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\WebhookEndpointValidationRules;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookEndpointController extends Controller
{
    use WebhookEndpointValidationRules;

    /**
     * List the team's endpoints and how their recent deliveries went.
     *
     * The signing secret is never sent. What is sent is what answers "is this working?" — the last few
     * deliveries and their status, which is the question someone opens this page to ask.
     */
    public function index(Request $request, Team $team): Response
    {

        $endpoints = $team->webhooks()
            ->with(['creator', 'deliveries' => fn ($query) => $query->latest()->limit(5)])
            ->withCount(['deliveries as failed_deliveries_count' => fn ($query) => $query->whereNotNull('failed_at')])
            ->latest()
            ->get();

        return Inertia::render('settings/webhooks', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'endpoints' => $endpoints->map($this->present(...))->all(),
        ]);
    }

    /**
     * Flatten one endpoint for the page.
     *
     * @return array<string, mixed>
     */
    protected function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'events' => $endpoint->events,
            'active' => $endpoint->isActive(),
            'created_by' => $endpoint->creator?->name,
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'failed_deliveries_count' => (int) $endpoint->failed_deliveries_count,
            'recent_deliveries' => $endpoint->deliveries->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'event_type' => $delivery->event_type,
                'response_status' => $delivery->response_status,
                'attempts' => $delivery->attempts,
                'succeeded' => $delivery->succeeded(),
                'created_at' => $delivery->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Register an endpoint and hand back its signing secret.
     *
     * Flashed rather than stored: it appears once on the next render and is then only on the customer's
     * side. Unlike an API key the secret is kept here too, because signing needs the value itself — but
     * showing it again would make it a second thing to leak.
     */
    public function store(Request $request, Team $team): RedirectResponse
    {

        $validated = $request->validate($this->webhookEndpointRules());

        $endpoint = $team->webhooks()->create([
            ...$validated,
            'secret' => WebhookEndpoint::mintSecret(),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('secret', $endpoint->secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint added. Copy the signing secret now — it will not be shown again.')]);

        return to_route('webhooks.index', ['team' => $team->slug]);
    }

    /**
     * Turn an endpoint off or back on.
     *
     * Disabling rather than deleting, so a URL that started failing can be stopped without losing the
     * delivery history that explains why.
     */
    public function update(Request $request, Team $team, WebhookEndpoint $webhook): RedirectResponse
    {

        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $webhook->forceFill(['disabled_at' => $validated['active'] ? null : now()])->save();

        return to_route('webhooks.index', ['team' => $team->slug]);
    }

    public function destroy(Request $request, Team $team, WebhookEndpoint $webhook): RedirectResponse
    {

        $webhook->delete();

        return to_route('webhooks.index', ['team' => $team->slug]);
    }
}
