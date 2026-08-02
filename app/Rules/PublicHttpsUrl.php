<?php

namespace App\Rules;

use App\Jobs\DeliverWebhook;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An https URL that points somewhere outside our own network.
 *
 * A webhook URL is the one place a customer gets to choose where our servers make a request to, which
 * makes it a server-side request forgery primitive unless the destination is constrained. Blocking the
 * private and reserved ranges stops an endpoint from being aimed at a metadata service, an internal
 * admin panel or a database's HTTP interface — all of which the queue worker can reach and the customer
 * cannot.
 *
 * This is validated again at delivery time, because a hostname that resolves publicly now can resolve
 * privately later — see {@see DeliverWebhook}.
 */
class PublicHttpsUrl implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isPublic($value)) {
            $fail('The :attribute must be an https URL reachable on the public internet.');
        }
    }

    /**
     * Whether every address this URL's host resolves to is publicly routable.
     *
     * A host with no resolvable address fails: we cannot show it is safe, and a webhook to nowhere is
     * not worth the benefit of the doubt.
     */
    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        $addresses = self::resolve($parts['host']);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            // FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE together reject loopback, link-local (which is
            // where cloud metadata services live), the RFC 1918 blocks and the reserved ranges.
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
