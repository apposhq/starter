<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reject a cursor the paginator cannot read.
 *
 * `Cursor::fromEncoded` answers null for anything it fails to decode, and the paginator treats null as
 * "start from the beginning". A caller whose cursor was truncated in a log, a queue payload or a URL
 * would silently restart its walk and process the same records again, with a 200 saying all is well.
 *
 * Middleware rather than a call in each list endpoint, because this is a property of the API and a new
 * endpoint should not be able to forget it.
 */
class ValidateApiCursor
{
    public function handle(Request $request, Closure $next): Response
    {
        $cursor = $request->query('cursor');

        if (filled($cursor) && ! Cursor::fromEncoded($cursor) instanceof Cursor) {
            throw new BadRequestHttpException(
                'The cursor is not readable. Use the `next_cursor` from a previous response, unmodified.'
            );
        }

        // A cursor that decodes cleanly can still belong to a different list — each endpoint encodes the
        // columns it orders by — and the paginator raises UnexpectedValueException when they are missing.
        // That is answered by ProblemDetails rather than caught here: Routing\Pipeline renders a
        // downstream throw at the pipe it happens in, so a catch out here would never see it.
        return $next($request);
    }
}
