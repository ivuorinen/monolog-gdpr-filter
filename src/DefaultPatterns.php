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
        // Patterns are applied in order, so the most specific must come first: a broader
        // pattern listed earlier would consume the match and mask it under the wrong label
        // (e.g. Medicare swallowing a US SSN, IPv6 swallowing a MAC address).
        //
        // Patterns are boundary-anchored (\b), not string-anchored (^...$), so they also
        // match sensitive values embedded in a log message. Only the generic API-key
        // heuristic stays whole-value anchored; unanchored it would match ordinary words.
        return [
            // Personal / national identifiers
            // Finnish SSN (HETU)
            '/\b\d{6}[-+A]?\d{3}[A-Z]\b/u' => Mask::MASK_HETU,
            // European Health Insurance Card (longest numeric grouping — match before
            // the shorter bank/health formats it would otherwise be split into)
            '/\b\d{2}[-\s]\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{1,4}\b/' => Mask::MASK_EHIC,
            // UK Sort Code + Account (6 digits + 8 digits)
            '/\b\d{6}[-\s]\d{8}\b/' => Mask::MASK_UKBANK,
            // Canadian Transit + Account (5 digits + 7-12 digits)
            '/\b\d{5}[-\s]\d{7,12}\b/' => Mask::MASK_CABANK,
            // Canadian Social Insurance Number (3-3-3 format)
            '/\b\d{3}[-\s]\d{3}[-\s]\d{3}\b/' => Mask::MASK_CASIN,
            // US Social Security Number (3-2-4, hyphens only) — must precede Medicare,
            // whose 3-2-4 form is otherwise identical
            '/\b\d{3}-\d{2}-\d{4}\b/' => Mask::MASK_USSSN,
            // US Medicare number (3-2-4, hyphen or space)
            '/\b\d{3}[-\s]\d{2}[-\s]\d{4}\b/' => Mask::MASK_MEDICARE,
            // UK National Insurance Number (2 letters, 6 digits, 1 letter)
            '/\b[A-Z]{2}\d{6}[A-Z]\b/' => Mask::MASK_UKNI,
            // Passport numbers (A followed by 6 digits)
            '/\bA\d{6}\b/' => Mask::MASK_PASSPORT,

            // Financial
            // IBAN (Finnish, grouped or compact)
            '/\bFI\d{2}(?: ?\d{4}){3} ?\d{2}\b/u' => Mask::MASK_IBAN,
            '/\bFI\d{16}\b/u' => Mask::MASK_IBAN,
            // Credit card numbers (Visa, MC, Amex, Discover test numbers)
            '/\b(?:4111 1111 1111 1111|5500-0000-0000-0004|340000000000009|6011000000000004)\b/'
                => Mask::MASK_CC,
            // Generic 16-digit credit card
            '/\b[0-9]{16}\b/u' => Mask::MASK_CC,

            // Dates of birth
            '/\b(?:19|20)\d{2}-[01]\d\-[0-3]\d\b/' => Mask::MASK_DOB,
            '/\b[0-3]\d\/[01]\d\/(?:19|20)\d{2}\b/' => Mask::MASK_DOB,

            // Contact details
            // International phone numbers (E.164, +countrycode...)
            '/(?<![\d+])\+\d{1,3}[\s-]?\d{1,4}[\s-]?\d{1,4}[\s-]?\d{1,9}\b/' => Mask::MASK_PHONE,
            // Email address
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => Mask::MASK_EMAIL,

            // Credentials
            // Bearer tokens (JWT, at least 10 chars after Bearer)
            '/\bBearer [A-Za-z0-9\-\._~\+\/]{10,}/' => Mask::MASK_TOKEN,
            // Stripe-style secret keys
            '/\bsk_(?:live|test)_[A-Za-z0-9]{16,}\b/' => Mask::MASK_APIKEY,

            // Network identifiers
            // MAC addresses — must precede IPv6, which also matches colon-separated hex
            '/\b(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/' => Mask::MASK_MAC,
            // IPv6 address (specific pattern with colons)
            '/\b[0-9a-fA-F]{1,4}:[0-9a-fA-F:]{7,35}\b/' => '***IPv6***',
            // IPv4 address (dotted decimal notation)
            '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/' => '***IPv4***',

            // Vehicle Registration Numbers (more specific patterns)
            // US License plates (specific formats: ABC-1234, ABC1234)
            '/\b[A-Z]{2,3}[-\s]?\d{3,4}\b/' => Mask::MASK_VEHICLE,
            // Reverse format (123-ABC)
            '/\b\d{3,4}[-\s]?[A-Z]{2,3}\b/' => Mask::MASK_VEHICLE,

            // Generic API-key heuristic: whole-value only. Unanchored, `[A-Za-z0-9\-_]{20,}`
            // would match any long word in a log message, so it stays string-anchored.
            '/^[A-Za-z0-9\-_]{20,}$/' => Mask::MASK_APIKEY,
        ];
    }
}
