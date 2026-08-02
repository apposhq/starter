<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\WebhookEndpointValidationRules;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @tags Webhook endpoints
 */
class WebhookEndpointController extends ApiController
{
    use WebhookEndpointValidationRules;

    /**
     * List webhook endpoints
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return WebhookEndpointResource::collection(
            $request->attributes->get('api_team')
                ->webhooks()
                ->orderBy('id')
                ->cursorPaginate(perPage: $this->perPage($request))
        );
    }

    /**
     * Create a webhook endpoint
     *
     * The signing secret is in the response and is not retrievable afterwards. Send this request with an
     * `Idempotency-Key` so a retry after a timeout does not register the endpoint twice.
     */
    public function store(Request $request): WebhookEndpointResource
    {
        $validated = $request->validate($this->webhookEndpointRules());

        $endpoint = $request->attributes->get('api_team')->webhooks()->create([
            ...$validated,
            'secret' => WebhookEndpoint::mintSecret(),
        ]);

        return new WebhookEndpointResource($endpoint);
    }

    /**
     * Retrieve a webhook endpoint
     */
    public function show(Request $request, string $endpoint): WebhookEndpointResource
    {
        return new WebhookEndpointResource($this->scoped($request, $endpoint));
    }

    /**
     * Update a webhook endpoint
     */
    public function update(Request $request, string $endpoint): WebhookEndpointResource
    {
        $validated = $request->validate([
            ...$this->webhookEndpointRules(partial: true),
            'active' => ['sometimes', 'boolean'],
        ]);

        $model = $this->scoped($request, $endpoint);

        // `active` is the caller's word for it; the column records when it stopped, not a flag.
        $model->fill(Arr::except($validated, ['active']));

        if (array_key_exists('active', $validated)) {
            $model->disabled_at = $validated['active'] ? null : now();
        }

        $model->save();

        return new WebhookEndpointResource($model);
    }

    /**
     * Delete a webhook endpoint
     *
     * Deliveries already recorded go with it.
     */
    public function destroy(Request $request, string $endpoint): Response
    {
        $this->scoped($request, $endpoint)->delete();

        return response()->noContent();
    }

    /**
     * Resolve an endpoint that belongs to the calling team.
     *
     * 404 rather than 403 for another team's endpoint, so an id the caller may not see is never
     * confirmed to exist.
     */
    protected function scoped(Request $request, string $endpoint): WebhookEndpoint
    {
        return $request->attributes->get('api_team')
            ->webhooks()
            ->find($endpoint) ?? throw new NotFoundHttpException('Webhook endpoint not found.');
    }
}
