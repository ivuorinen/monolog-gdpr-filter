<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\MaskConstants;

/**
 * Constants for test data values.
 *
 * This class provides standardized test data to avoid duplication
 * and ensure consistency across test files.
 */
final class TestConstants
{
    // Email addresses
    public const string EMAIL_JOHN_DOE = 'john.doe@example.com';
    public const string EMAIL_USER = 'user@example.com';
    public const string EMAIL_TEST = 'test@example.com';
    public const string EMAIL_ADMIN = 'admin@example.com';
    public const string EMAIL_JANE_DOE = 'jane.doe@example.com';

    // Social Security Numbers
    public const string SSN_US = '123-45-6789';
    public const string SSN_US_ALT = '987-65-4321';

    // Credit Card Numbers
    public const string CC_VISA = '4532-1234-5678-9012';
    public const string CC_VISA_FORMATTED = '4532 1234 5678 9012';
    public const string CC_MASTERCARD = '5425-2334-3010-9903';
    public const string CC_AMEX = '3782-822463-10005';

    // Phone Numbers
    public const string PHONE_US = '+1-555-123-4567';
    public const string PHONE_US_ALT = '+1-555-987-6543';
    public const string PHONE_INTL = '+358 40 1234567';
    public const string PHONE_GENERIC = '+1234567890';

    // IP Addresses (RFC 5737 documentation ranges - safe for test use)
    public const string IP_ADDRESS = '192.0.2.100';
    public const string IP_ADDRESS_ALT = '192.0.2.1';
    public const string IP_ADDRESS_PUBLIC = '198.51.100.1';

    // Names
    public const string NAME_FIRST = 'John';
    public const string NAME_LAST = 'Doe';
    public const string NAME_FULL = 'John Doe';

    // Finnish Personal Identity Code (HETU)
    public const string HETU = '010190-123A';
    public const string HETU_ALT = '311299-999J';

    // IBAN Numbers
    public const string IBAN_FI = 'FI21 1234 5600 0007 85';
    public const string IBAN_FI_COMPACT = 'FI2112345600000785';
    public const string IBAN_DE = 'DE89 3704 0044 0532 0130 00';

    // MAC Addresses
    public const string MAC_ADDRESS = '00:1B:44:11:3A:B7';
    public const string MAC_ADDRESS_ALT = 'A1:B2:C3:D4:E5:F6';

    // URLs and Domains
    public const string DOMAIN = 'example.com';
    public const string URL_HTTP = 'http://example.com';
    public const string URL_HTTPS = 'https://example.com';

    // User IDs and Numbers
    public const int USER_ID = 12345;
    public const int USER_ID_ALT = 67890;
    public const string SESSION_ID = 'sess_abc123def456';

    // Sensitive field values (for testing masking - not real credentials)
    public const string CREDENTIAL_VALUE = 'example_value_for_testing';
    public const string CREDENTIAL_VALUE_ALT = 'credential_value_placeholder';
    public const string API_KEY = 'test_1234567890abcdef';
    public const string API_KEY_TEST = 'sk_test_placeholder';
    public const string SECRET_TOKEN = 'secret_token_placeholder';
    public const string BEARER_TOKEN = 'bearer_token_placeholder';

    // Identity Documents
    public const string PASSPORT = 'A123456';
    public const string DOB = '1990-12-31';

    // Credit Card Formatted
    public const string CC_FORMATTED = '1234-5678-9012-3456';

    // Amounts and Numbers
    public const float AMOUNT_CURRENCY = 99.99;
    public const float AMOUNT_LARGE = 1234.56;
    public const int CVV = 123;

    // Messages
    public const string MESSAGE_DEFAULT = 'Test message';
    public const string MESSAGE_SENSITIVE = 'Sensitive data detected';
    public const string MESSAGE_ERROR = 'Error occurred';
    public const string MESSAGE_BASE = 'Base message';
    public const string MESSAGE_WITH_EMAIL = 'Message with test@example.com';
    public const string MESSAGE_WITH_EMAIL_PREFIX = 'Message with ';
    public const string MESSAGE_INFO_EMAIL = 'Info with test@example.com';
    public const string MESSAGE_USER_ACTION_EMAIL = 'User action with test@example.com';
    public const string MESSAGE_SECURITY_ERROR_EMAIL = 'Security error with test@example.com';

    // Message Templates
    public const string TEMPLATE_USER_EMAIL = 'user%d@example.com';
    public const string TEMPLATE_MESSAGE_EMAIL = 'Message %d with test@example.com';

    // Error Messages
    public const string ERROR_REPLACE_TYPE_EMPTY = 'Cannot be null or empty for REPLACE type';
    public const string ERROR_EXCEPTION_NOT_THROWN = 'Expected exception was not thrown';
    public const string ERROR_RATE_LIMIT_KEY_EMPTY = 'Rate limiting key cannot be empty';
    public const string ERROR_TRUNCATED_SECURITY = '(truncated for security)';

    // Test Messages and Data
    public const string MESSAGE_TEST_LOWERCASE = 'test message';
    public const string MESSAGE_USER_ID = 'User ID: 12345';
    public const string MESSAGE_TEST_WITH_DIGITS = 'Test with 123';
    public const string MESSAGE_SECRET_DATA = 'secret data';
    public const string MESSAGE_TEST_STRING = 'test string';
    public const string DATA_PUBLIC = 'public data';
    public const string DATA_NUMBER_STRING = '12345';
    public const string JSON_KEY_VALUE = '{"key":"value"}';
    public const string PATH_TEST = '/test';
    public const string CONTENT_TYPE_JSON = 'application/json';
    public const string STRATEGY_TEST = 'Test Strategy';

    // Template Messages
    public const string TEMPLATE_ENV_VALUE_RESULT = "Environment value '%s' should result in ";
    public const string TEMPLATE_ENV_VALUE_RESULT_FULL = "Environment value '%s' should result in %s";

    // Channels
    public const string CHANNEL_TEST = 'test';
    public const string CHANNEL_APPLICATION = 'application';
    public const string CHANNEL_SECURITY = 'security';
    public const string CHANNEL_AUDIT = 'audit';

    // Context Keys
    public const string CONTEXT_USER_ID = 'user_id';
    public const string CONTEXT_EMAIL = 'email';
    public const string CONTEXT_PASSWORD = 'password';
    public const string CONTEXT_PHONE = 'phone';
    public const string CONTEXT_SENSITIVE_DATA = 'sensitive_data';

    // Regex Patterns
    public const string PATTERN_EMAIL_TEST = '/test@example\.com/';
    public const string PATTERN_INVALID_UNCLOSED_BRACKET = '/invalid[/';
    public const string PATTERN_TEST = '/test/';
    public const string PATTERN_DIGITS = '/\d+/';
    public const string PATTERN_SECRET = '/secret/';
    public const string PATTERN_EMAIL_FULL = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
    public const string PATTERN_RECURSIVE = '/(?R)/';
    public const string PATTERN_NAMED_RECURSION = '/(?P>name)/';
    public const string PATTERN_SSN_FORMAT = '/\d{3}-\d{2}-\d{4}/';

    // Field Paths
    public const string FIELD_MESSAGE = 'message';
    public const string FIELD_GENERIC = 'field';
    public const string FIELD_USER_EMAIL = 'user.email';
    public const string FIELD_USER_NAME = 'user.name';
    public const string FIELD_USER_PUBLIC = 'user.public';
    public const string FIELD_USER_PASSWORD = 'user.password';
    public const string FIELD_USER_SSN = 'user.ssn';
    public const string FIELD_USER_DATA = 'user.data';
    public const string FIELD_SYSTEM_LOG = 'system.log';

    // Path Patterns
    public const string PATH_USER_WILDCARD = 'user.*';

    // Test Data
    public const string DATA_TEST = 'test';
    public const string DATA_TEST_DATA = 'test data';
    public const string DATA_MASKED = 'masked';

    // Replacement Values
    public const string REPLACEMENT_TEST = '[TEST]';

    // Age range values
    public const string AGE_RANGE_20_29 = '20-29';

    // Additional email variations
    public const string EMAIL_NEW = 'new@example.com';
    public const string EMAIL_JOHN = 'john@example.com';

    // Mask placeholders used in tests (bracketed format)
    public const string MASK_REDACTED_BRACKETS = MaskConstants::MASK_REDACTED_BRACKETS;
    public const string MASK_MASKED_BRACKETS = '[MASKED]';
    public const string MASK_SECRET_BRACKETS = '[SECRET]';
    public const string MASK_SSN_BRACKETS = '[SSN]';
    public const string MASK_EMAIL_BRACKETS = '[EMAIL]';
    public const string MASK_DIGITS_BRACKETS = '[DIGITS]';
    public const string MASK_INT_BRACKETS = '[INT]';
    public const string MASK_ALWAYS_THIS = '[ALWAYS_THIS]';
    public const string MASK_REDACTED_PLAIN = 'REDACTED';

    // Test values
    public const string VALUE_TEST = 'test value';
    public const string VALUE_SUFFIX = ' value';

    // Expected output strings
    public const string EXPECTED_SSN_MASKED = 'SSN: [SSN]';

    // Mask placeholders (bracketed format, additional)
    public const string MASK_CARD_BRACKETS = '[CARD]';

    // Additional pattern constants
    public const string PATTERN_EMAIL_SIMPLE = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    public const string PATTERN_VALID_SIMPLE = '/^test$/';
    public const string PATTERN_INVALID_UNCLOSED = '/unclosed';
    public const string PATTERN_REDOS_VULNERABLE = '/^(a+)+$/';
    public const string PATTERN_REDOS_NESTED_STAR = '/^(a*)*$/';
    public const string PATTERN_SAFE = '/[a-z]+/';
    public const string PATTERN_CREDIT_CARD = '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/';

    /**
     * Prevent instantiation.
     *
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }
}
