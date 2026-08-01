<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Attaches {@see FlattenExceptionContext} to this channel's handlers rather than to the logger.
 *
 * A `processors` entry in a channel's config is pushed onto the Logger, and `LogManager::createStackDriver`
 * builds one Logger for the whole stack — so the OTLP channel's processor would also rewrite every record
 * on its way to `stderr`, replacing the Throwable that Monolog's formatter renders for a human reading
 * `mise dev`. A handler-level processor runs only for the handler it belongs to.
 */
class FlattenExceptionsOnHandler
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // getHandlers() is Monolog's, not PSR-3's; Laravel's Logger only promises the interface.
        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new FlattenExceptionContext);
            }
        }
    }
}
