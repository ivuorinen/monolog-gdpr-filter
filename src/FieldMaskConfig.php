<?php

namespace Ivuorinen\MonologGdprFilter;

/**
 * FieldMaskConfig: config for masking/removal per field path
 */
final class FieldMaskConfig
{
    public const MASK_REGEX = 'mask_regex';

    public const REMOVE = 'remove';

    public const REPLACE = 'replace';

    public function __construct(public string $type, public ?string $replacement = null)
    {
    }
}
