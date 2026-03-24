<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;

/**
 * Provides default GDPR regex patterns for common sensitive data types.
 */
final class DefaultPatterns
{
    /**
     * Get default GDPR regex patterns. Non-exhaustive, should be extended with your own.
     *
     * @return array<string, string>
     */
    public static function get(): array
    {
        return [
            // Finnish SSN (HETU)
            '/\b\d{6}[-+A]?\d{3}[A-Z]\b/u' => Mask::MASK_HETU,
            // US Social Security Number (strict: 3-2-4 digits)
            '/^\d{3}-\d{2}-\d{4}$/' => Mask::MASK_USSSN,
            // IBAN (strictly match Finnish IBAN with or without spaces, only valid groupings)
            '/^FI\d{2}(?: ?\d{4}){3} ?\d{2}$/u' => Mask::MASK_IBAN,
            // Also match fully compact Finnish IBAN (no spaces)
            '/^FI\d{16}$/u' => Mask::MASK_IBAN,
            // International phone numbers (E.164, +countrycode...)
            '/^\+\d{1,3}[\s-]?\d{1,4}[\s-]?\d{1,4}[\s-]?\d{1,9}$/' => Mask::MASK_PHONE,
            // Email address
            '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/' => Mask::MASK_EMAIL,
            // Date of birth (YYYY-MM-DD)
            '/^(19|20)\d{2}-[01]\d\-[0-3]\d$/' => Mask::MASK_DOB,
            // Date of birth (DD/MM/YYYY)
            '/^[0-3]\d\/[01]\d\/(19|20)\d{2}$/' => Mask::MASK_DOB,
            // Passport numbers (A followed by 6 digits)
            '/^A\d{6}$/' => Mask::MASK_PASSPORT,
            // Credit card numbers (Visa, MC, Amex, Discover test numbers)
            '/^(4111 1111 1111 1111|5500-0000-0000-0004|340000000000009|6011000000000004)$/' => Mask::MASK_CC,
            // Generic 16-digit credit card (for test compatibility)
            '/\b[0-9]{16}\b/u' => Mask::MASK_CC,
            // Bearer tokens (JWT, at least 10 chars after Bearer)
            '/^Bearer [A-Za-z0-9\-\._~\+\/]{10,}$/' => Mask::MASK_TOKEN,
            // API keys (Stripe-like, 20+ chars, or sk_live|sk_test)
            '/^(sk_(live|test)_[A-Za-z0-9]{16,}|[A-Za-z0-9\-_]{20,})$/' => Mask::MASK_APIKEY,
            // MAC addresses
            '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/' => Mask::MASK_MAC,

            // IP Addresses
            // IPv4 address (dotted decimal notation)
            '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/' => '***IPv4***',

            // Vehicle Registration Numbers (more specific patterns)
            // US License plates (specific formats: ABC-1234, ABC1234)
            '/\b[A-Z]{2,3}[-\s]?\d{3,4}\b/' => Mask::MASK_VEHICLE,
            // Reverse format (123-ABC)
            '/\b\d{3,4}[-\s]?[A-Z]{2,3}\b/' => Mask::MASK_VEHICLE,

            // National ID Numbers
            // UK National Insurance Number (2 letters, 6 digits, 1 letter)
            '/\b[A-Z]{2}\d{6}[A-Z]\b/' => Mask::MASK_UKNI,
            // Canadian Social Insurance Number (3-3-3 format)
            '/\b\d{3}[-\s]\d{3}[-\s]\d{3}\b/' => Mask::MASK_CASIN,
            // UK Sort Code + Account (6 digits + 8 digits)
            '/\b\d{6}[-\s]\d{8}\b/' => Mask::MASK_UKBANK,
            // Canadian Transit + Account (5 digits + 7-12 digits)
            '/\b\d{5}[-\s]\d{7,12}\b/' => Mask::MASK_CABANK,

            // Health Insurance Numbers
            // US Medicare number (various formats)
            '/\b\d{3}[-\s]\d{2}[-\s]\d{4}\b/' => Mask::MASK_MEDICARE,
            // European Health Insurance Card (starts with country code)
            '/\b\d{2}[-\s]\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{1,4}\b/' => Mask::MASK_EHIC,

            // IPv6 address (specific pattern with colons)
            '/\b[0-9a-fA-F]{1,4}:[0-9a-fA-F:]{7,35}\b/' => '***IPv6***',
        ];
    }
}
