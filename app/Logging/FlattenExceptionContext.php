<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

/**
 * Replaces the Throwable that Laravel's reporter puts in log context with scalar fields.
 *
 * The OpenTelemetry handler copies context entries straight into OTLP attributes, and a Throwable
 * exposes no public properties, so it serializes to an empty object: the record that reaches
 * OpenObserve carries no class, message, file, line or trace. Diagnosing a 500 then means shelling into
 * the container to read the file log, which defeats the point of shipping logs off the box at all.
 */
class FlattenExceptionContext implements ProcessorInterface
{
    /**
     * Traces run to tens of kilobytes and are attributes on every error record, so they are capped
     * rather than shipped whole. The frames that identify a fault are at the top.
     */
    protected const TRACE_LIMIT = 4000;

    public function __invoke(LogRecord $record): LogRecord
    {
        $exception = $record->context['exception'] ?? null;

        if (! $exception instanceof Throwable) {
            return $record;
        }

        // Semantic-convention names, not invented ones: OpenObserve's exception panels key off these,
        // so an ad-hoc `exception.trace` lands as a field the default views do not read — the same
        // "shipped but invisible" outcome this class exists to prevent.
        // The Throwable itself is dropped, not kept alongside: the handler copies context into attributes
        // verbatim, and an object with no public properties serializes to an empty `exception` field —
        // the useless payload this class exists to replace.
        $context = $record->context;
        unset($context['exception']);

        return $record->with(context: [
            ...$context,
            TraceAttributes::EXCEPTION_TYPE => $exception::class,
            TraceAttributes::EXCEPTION_MESSAGE => $exception->getMessage(),
            'exception.location' => sprintf('%s:%d', $exception->getFile(), $exception->getLine()),
            // mb_strcut, not Str::limit: the latter opens with mb_strwidth over the whole string, so a
            // 40 KB trace is decoded twice to keep the first 4 KB — on the exception path.
            TraceAttributes::EXCEPTION_STACKTRACE => mb_strcut($exception->getTraceAsString(), 0, self::TRACE_LIMIT),
        ]);
    }
}
