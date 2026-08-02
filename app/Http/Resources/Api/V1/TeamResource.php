<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, slug: string, personal: bool, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** The team's stable identifier. */
            'id' => (string) $this->id,
            /** Display name. */
            'name' => $this->name,
            /** URL-safe identifier, unique across the platform. */
            'slug' => $this->slug,
            /** Whether this is the owner's personal team, which cannot be deleted or left. */
            'personal' => (bool) $this->resource->is_personal,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
