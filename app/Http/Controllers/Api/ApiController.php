<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Shared behaviour for the platform API.
 *
 * The page-size cap lives here rather than in each list endpoint because it is a limit on what one
 * request can cost the database, not a per-endpoint preference — an endpoint added later cannot forget
 * to apply it.
 */
abstract class ApiController extends Controller
{
    /**
     * The page size for a list request, clamped to the configured ceiling.
     *
     * A caller asking for more than the cap gets the cap rather than an error: the request is still
     * answerable, and failing it would only teach them to retry at exactly the limit.
     */
    protected function perPage(Request $request): int
    {
        // filter_var rather than $request->integer(), which intvals a present-but-non-numeric value to 0
        // and would silently hand back a page size of 1 — 25x the round trips against the caller's budget.
        $requested = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if ($requested === false) {
            $requested = (int) config('api.pagination.per_page');
        }

        return max(1, min($requested, (int) config('api.pagination.max_per_page')));
    }
}
