<?php

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Renders API errors as RFC 9457 problem details.
 *
 * A public API's errors are part of its contract: customers write code against them, so `{"message":
 * "Server Error"}` is not enough to act on. RFC 9457 (July 2023, obsoleting RFC 7807) is the format
 * HTTP APIs converged on — `application/problem+json` with `type`, `title`, `status`, `detail` and
 * `instance`, plus any extension members the API wants to add.
 *
 * The extension added here is `trace_id`. Every request already produces an OpenTelemetry trace, so
 * quoting that id in a support ticket leads straight to the spans, SQL and logs behind the failure.
 */
class ProblemDetails
{
    /**
     * Problem types, keyed by status. `type` is a URI that identifies the *kind* of problem, and is
     * meant to be stable and dereferenceable — a customer can link to it from their own runbook.
     */
    protected const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthenticated',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        $status = $this->status($e, $request);

        $problem = [
            'type' => url("/docs/problems/{$status}"),
            'title' => self::TITLES[$status] ?? 'Error',
            'status' => $status,
            'detail' => $this->detail($e, $status),
            'instance' => $request->getRequestUri(),
        ];

        // The one extension member. Named in snake_case to match the rest of the API's payloads.
        if (filled($traceId = Tracer::traceId())) {
            $problem['trace_id'] = $traceId;
        }

        // Validation failures carry the field-level detail a client needs to highlight inputs. RFC 9457
        // allows extension members precisely so this does not have to become a different envelope.
        if ($e instanceof ValidationException) {
            $problem['errors'] = $e->errors();
        }

        // An HttpException carries headers that are part of the answer, not decoration: Retry-After on a
        // 429 is how a client knows when to come back, and dropping it turns a recoverable throttle into
        // a guess. Content-Type is set last so nothing can override the problem media type.
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($problem, $status, [
            ...$headers,
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /**
     * Map an exception to a status.
     *
     * Authentication and authorization failures are not HttpExceptions, so without naming them here a
     * missing API key would answer 500 — which tells a customer to open a ticket for their own mistake.
     */
    protected function status(Throwable $e, Request $request): int
    {
        return match (true) {
            $e instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $e instanceof AuthorizationException => Response::HTTP_FORBIDDEN,
            $e instanceof ModelNotFoundException => Response::HTTP_NOT_FOUND,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            // The paginator's answer to a cursor that names columns this list does not order by. The
            // cursor came from the client, so this is their mistake to fix, not a server fault.
            $e instanceof UnexpectedValueException && filled($request->query('cursor')) => Response::HTTP_BAD_REQUEST,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * A human-readable explanation, without leaking internals on a 500.
     *
     * An unhandled exception's message can carry a query, a path or a credential, so it is only shown
     * when the application is already in debug mode.
     */
    protected function detail(Throwable $e, int $status): string
    {
        if ($status >= 500 && ! config('app.debug')) {
            return 'The server encountered an unexpected condition. Quote the trace_id when contacting support.';
        }

        return $e->getMessage() !== '' ? $e->getMessage() : (self::TITLES[$status] ?? 'Error');
    }
}
