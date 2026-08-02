<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * @property-read Membership $pivot
 */
class MemberResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, email: string, role: string, joined_at: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** The member's stable identifier. */
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            /** The member's role in this team: owner, admin or member. */
            'role' => $this->whenPivotLoaded('team_members', fn (): string => $this->pivot->role->value),
            /** When the member joined this team. */
            'joined_at' => $this->whenPivotLoaded('team_members', fn (): ?string => $this->pivot->created_at?->toIso8601String()),
        ];
    }
}
