<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array{id: string, url: string, description: ?string, events: array<int, string>, active: bool, secret?: string, created_at: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** The endpoint's stable identifier. */
            'id' => (string) $this->id,
            /** Where deliveries are POSTed. */
            'url' => $this->url,
            'description' => $this->description,
            /** Event types this endpoint receives. `*` means every type, including ones added later. */
            'events' => $this->events,
            /** Whether deliveries are currently being sent. */
            'active' => $this->isActive(),
            /**
             * The signing secret. Returned only when the endpoint is created — it is not recoverable
             * afterwards, and every delivery is signed with it.
             */
            'secret' => $this->when($request->isMethod('POST'), fn (): string => $this->secret),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
