<?php

namespace Ivuorinen\MonologGdprFilter\Laravel\Facades;

use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade for GDPR Processor.
 *
 * @method static string regExpMessage(string $message = '')
 * @method static array<string, string> getDefaultPatterns()
 * @method static FieldMaskConfig maskWithRegex()
 * @method static FieldMaskConfig removeField()
 * @method static FieldMaskConfig replaceWith(string $replacement)
 * @method static void validatePatterns(array<string, string> $patterns)
 * @method static void clearPatternCache()
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
