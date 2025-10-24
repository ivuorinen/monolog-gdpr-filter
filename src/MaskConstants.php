<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

/**
 * Constants for mask replacement values.
 *
 * This class provides standardized mask values to avoid duplication
 * and ensure consistency across the codebase.
 */
final class MaskConstants
{
    // Data type masks
    public const MASK_INT = '***INT***';
    public const MASK_FLOAT = '***FLOAT***';
    public const MASK_STRING = '***STRING***';
    public const MASK_BOOL = '***BOOL***';
    public const MASK_NULL = '***NULL***';
    public const MASK_ARRAY = '***ARRAY***';
    public const MASK_OBJECT = '***OBJECT***';
    public const MASK_RESOURCE = '***RESOURCE***';

    // Generic masks
    public const MASK_GENERIC = '***';              // Simple generic mask
    public const MASK_MASKED = '***MASKED***';
    public const MASK_REDACTED = '***REDACTED***';
    public const MASK_FILTERED = '***FILTERED***';
    public const MASK_BRACKETS = '[MASKED]';

    // Personal identifiers
    public const MASK_HETU = '***HETU***';          // Finnish SSN
    public const MASK_SSN = '***SSN***';            // Generic SSN
    public const MASK_USSSN = '***USSSN***';        // US SSN
    public const MASK_UKNI = '***UKNI***';          // UK National Insurance
    public const MASK_CASIN = '***CASIN***';        // Canadian SIN
    public const MASK_PASSPORT = '***PASSPORT***';

    // Financial information
    public const MASK_IBAN = '***IBAN***';
    public const MASK_CC = '***CC***';              // Credit Card
    public const MASK_CARD = '***CARD***';          // Credit Card (alternative)
    public const MASK_UKBANK = '***UKBANK***';
    public const MASK_CABANK = '***CABANK***';

    // Contact information
    public const MASK_EMAIL = '***EMAIL***';
    public const MASK_PHONE = '***PHONE***';
    public const MASK_IP = '***IP***';

    // Security tokens and keys
    public const MASK_TOKEN = '***TOKEN***';
    public const MASK_APIKEY = '***APIKEY***';
    public const MASK_SECRET = '***SECRET***';

    // Personal data
    public const MASK_DOB = '***DOB***';            // Date of Birth
    public const MASK_MAC = '***MAC***';            // MAC Address

    // Vehicle and identification
    public const MASK_VEHICLE = '***VEHICLE***';

    // Healthcare
    public const MASK_MEDICARE = '***MEDICARE***';
    public const MASK_EHIC = '***EHIC***';          // European Health Insurance Card

    // Custom/Internal
    public const MASK_INTERNAL = '***INTERNAL***';
    public const MASK_CUSTOMER = '***CUSTOMER***';
    public const MASK_NUMBER = '***NUMBER***';
    public const MASK_ITEM = '***ITEM***';

    // Custom mask patterns for partial masking
    public const MASK_SSN_PATTERN = '***-**-****';      // SSN with format preserved
    public const MASK_EMAIL_PATTERN = '***@***.***';    // Email with format preserved

    // Error states
    public const MASK_INVALID = '***INVALID***';
    public const MASK_TOOLONG = '***TOOLONG***';
    public const MASK_ERROR = '***ERROR***';

    /**
     * Prevent instantiation.
     *
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {}
}
