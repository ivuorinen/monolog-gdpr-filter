<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Facades;

use Monolog\LogRecord;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade for GDPR Processor.
 *
 * @method static string regExpMessage(string $message = '')
 * @method static string maskMessage(string $value = '')
 * @method static array|string recursiveMask(array|string $data, int $currentDepth = 0)
 * @method static array<string, string> getDefaultPatterns()
 * @method static void validatePatternsArray(array<string, string> $patterns)
 * @method static void setAuditLogger(?callable $auditLogger)
 * @method static LogRecord __invoke(LogRecord $record)
 *
 * @see \Ivuorinen\MonologGdprFilter\GdprProcessor
 * @api
 */
class Gdpr extends Facade
{
    /**
     * Get the registered name of the component.
     *
     *
     * @psalm-return 'gdpr.processor'
     */
    protected static function getFacadeAccessor(): string
    {
        return 'gdpr.processor';
    }
}
