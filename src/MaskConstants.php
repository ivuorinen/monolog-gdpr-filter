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
    public const string MASK_INT = '***INT***';
    public const string MASK_FLOAT = '***FLOAT***';
    public const string MASK_STRING = '***STRING***';
    public const string MASK_BOOL = '***BOOL***';
    public const string MASK_NULL = '***NULL***';
    public const string MASK_ARRAY = '***ARRAY***';
    public const string MASK_OBJECT = '***OBJECT***';
    public const string MASK_RESOURCE = '***RESOURCE***';

    // Generic masks
    public const string MASK_GENERIC = '***';              // Simple generic mask
    public const string MASK_MASKED = '***MASKED***';
    public const string MASK_REDACTED = '***REDACTED***';
    public const string MASK_FILTERED = '***FILTERED***';
    public const string MASK_BRACKETS = '[MASKED]';
    public const string MASK_REDACTED_BRACKETS = '[REDACTED]';

    // Personal identifiers
    public const string MASK_HETU = '***HETU***';          // Finnish SSN
    public const string MASK_SSN = '***SSN***';            // Generic SSN
    public const string MASK_USSSN = '***USSSN***';        // US SSN
    public const string MASK_UKNI = '***UKNI***';          // UK National Insurance
    public const string MASK_CASIN = '***CASIN***';        // Canadian SIN
    public const string MASK_PASSPORT = '***PASSPORT***';

    // Financial information
    public const string MASK_IBAN = '***IBAN***';
    public const string MASK_CC = '***CC***';              // Credit Card
    public const string MASK_CARD = '***CARD***';          // Credit Card (alternative)
    public const string MASK_UKBANK = '***UKBANK***';
    public const string MASK_CABANK = '***CABANK***';

    // Contact information
    public const string MASK_EMAIL = '***EMAIL***';
    public const string MASK_PHONE = '***PHONE***';
    public const string MASK_IP = '***IP***';

    // Security tokens and keys
    public const string MASK_TOKEN = '***TOKEN***';
    public const string MASK_APIKEY = '***APIKEY***';
    public const string MASK_SECRET = '***SECRET***';

    // Personal data
    public const string MASK_DOB = '***DOB***';            // Date of Birth
    public const string MASK_MAC = '***MAC***';            // MAC Address

    // Vehicle and identification
    public const string MASK_VEHICLE = '***VEHICLE***';

    // Healthcare
    public const string MASK_MEDICARE = '***MEDICARE***';
    public const string MASK_EHIC = '***EHIC***';          // European Health Insurance Card

    // Custom/Internal
    public const string MASK_INTERNAL = '***INTERNAL***';
    public const string MASK_CUSTOMER = '***CUSTOMER***';
    public const string MASK_NUMBER = '***NUMBER***';
    public const string MASK_ITEM = '***ITEM***';

    // Custom mask patterns for partial masking
    public const string MASK_SSN_PATTERN = '***-**-****';      // SSN with format preserved
    public const string MASK_EMAIL_PATTERN = '***@***.***';    // Email with format preserved

    // Error states
    public const string MASK_INVALID = '***INVALID***';
    public const string MASK_TOOLONG = '***TOOLONG***';
    public const string MASK_ERROR = '***ERROR***';

    /**
     * Prevent instantiation.
     *
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {}
}
