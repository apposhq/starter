<?php

namespace App\Concerns;

use App\Rules\PublicHttpsUrl;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rules an endpoint must satisfy, wherever it is registered.
 *
 * Shared between the platform API and the settings UI because the https-only policy is a security
 * decision, not a per-surface preference: three copies means changing it in three places, and the one
 * that gets missed accepts an `http://` endpoint the others reject.
 */
trait WebhookEndpointValidationRules
{
    /**
     * @param  bool  $partial  whether an absent field means "leave it alone" rather than "required"
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function webhookEndpointRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            // https only, and pointed outside our own network: this is the one URL a customer chooses
            // that our servers then request, which makes it an SSRF primitive without the second check.
            // A delivery is signed but not encrypted, and the payload describes the customer's own data.
            'url' => [$presence, 'url:https', 'max:2000', new PublicHttpsUrl],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => [$presence, 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:255'],
        ];
    }
}
