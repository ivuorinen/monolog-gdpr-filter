<?php

declare(strict_types=1);

namespace Tests;

/**
 * Constants for test data values.
 *
 * This class provides standardized test data to avoid duplication
 * and ensure consistency across test files.
 */
final class TestConstants
{
    // Email addresses
    public const EMAIL_JOHN_DOE = 'john.doe@example.com';
    public const EMAIL_USER = 'user@example.com';
    public const EMAIL_TEST = 'test@example.com';
    public const EMAIL_ADMIN = 'admin@example.com';
    public const EMAIL_JANE_DOE = 'jane.doe@example.com';

    // Social Security Numbers
    public const SSN_US = '123-45-6789';
    public const SSN_US_ALT = '987-65-4321';

    // Credit Card Numbers
    public const CC_VISA = '4532-1234-5678-9012';
    public const CC_VISA_FORMATTED = '4532 1234 5678 9012';
    public const CC_MASTERCARD = '5425-2334-3010-9903';
    public const CC_AMEX = '3782-822463-10005';

    // Phone Numbers
    public const PHONE_US = '+1-555-123-4567';
    public const PHONE_US_ALT = '+1-555-987-6543';
    public const PHONE_GENERIC = '+1234567890';

    // IP Addresses
    public const IP_ADDRESS = '192.168.1.100';
    public const IP_ADDRESS_ALT = '192.168.1.1';
    public const IP_ADDRESS_PUBLIC = '8.8.8.8';

    // Names
    public const NAME_FIRST = 'John';
    public const NAME_LAST = 'Doe';
    public const NAME_FULL = 'John Doe';

    // Finnish Personal Identity Code (HETU)
    public const HETU = '010190-123A';
    public const HETU_ALT = '311299-999J';

    // IBAN Numbers
    public const IBAN_FI = 'FI21 1234 5600 0007 85';
    public const IBAN_DE = 'DE89 3704 0044 0532 0130 00';

    // MAC Addresses
    public const MAC_ADDRESS = '00:1B:44:11:3A:B7';
    public const MAC_ADDRESS_ALT = 'A1:B2:C3:D4:E5:F6';

    // URLs and Domains
    public const DOMAIN = 'example.com';
    public const URL_HTTP = 'http://example.com';
    public const URL_HTTPS = 'https://example.com';

    // User IDs and Numbers
    public const USER_ID = 12345;
    public const USER_ID_ALT = 67890;
    public const SESSION_ID = 'sess_abc123def456';

    // Passwords and Secrets (for testing masking)
    public const PASSWORD = 'secret_password_123';
    public const PASSWORD_ALT = 'p@ssw0rd!';
    public const API_KEY = 'sk_live_1234567890abcdef';
    public const SECRET_TOKEN = 'bearer_secret_token';

    // Amounts and Numbers
    public const AMOUNT_CURRENCY = 99.99;
    public const AMOUNT_LARGE = 1234.56;
    public const CVV = 123;

    // Messages
    public const MESSAGE_DEFAULT = 'Test message';
    public const MESSAGE_SENSITIVE = 'Sensitive data detected';
    public const MESSAGE_ERROR = 'Error occurred';
    public const MESSAGE_BASE = 'Base message';
    public const MESSAGE_WITH_EMAIL = 'Message with test@example.com';
    public const MESSAGE_WITH_EMAIL_PREFIX = 'Message with ';
    public const MESSAGE_INFO_EMAIL = 'Info with test@example.com';
    public const MESSAGE_USER_ACTION_EMAIL = 'User action with test@example.com';
    public const MESSAGE_SECURITY_ERROR_EMAIL = 'Security error with test@example.com';

    // Message Templates
    public const TEMPLATE_USER_EMAIL = 'user%d@example.com';
    public const TEMPLATE_MESSAGE_EMAIL = 'Message %d with test@example.com';

    // Error Messages
    public const ERROR_REPLACE_TYPE_EMPTY = 'Cannot be null or empty for REPLACE type';
    public const ERROR_EXCEPTION_NOT_THROWN = 'Expected exception was not thrown';
    public const ERROR_RATE_LIMIT_KEY_EMPTY = 'Rate limiting key cannot be empty';
    public const ERROR_TRUNCATED_SECURITY = '(truncated for security)';

    // Test Messages and Data
    public const MESSAGE_TEST_LOWERCASE = 'test message';
    public const MESSAGE_USER_ID = 'User ID: 12345';
    public const MESSAGE_TEST_WITH_DIGITS = 'Test with 123';
    public const MESSAGE_SECRET_DATA = 'secret data';
    public const MESSAGE_TEST_STRING = 'test string';
    public const DATA_PUBLIC = 'public data';
    public const DATA_NUMBER_STRING = '12345';
    public const JSON_KEY_VALUE = '{"key":"value"}';
    public const PATH_TEST = '/test';
    public const CONTENT_TYPE_JSON = 'application/json';
    public const STRATEGY_TEST = 'Test Strategy';

    // Template Messages
    public const TEMPLATE_ENV_VALUE_RESULT = "Environment value '%s' should result in ";
    public const TEMPLATE_ENV_VALUE_RESULT_FULL = "Environment value '%s' should result in %s";

    // Channels
    public const CHANNEL_TEST = 'test';
    public const CHANNEL_APPLICATION = 'application';
    public const CHANNEL_SECURITY = 'security';
    public const CHANNEL_AUDIT = 'audit';

    // Context Keys
    public const CONTEXT_USER_ID = 'user_id';
    public const CONTEXT_EMAIL = 'email';
    public const CONTEXT_PASSWORD = 'password';
    public const CONTEXT_SENSITIVE_DATA = 'sensitive_data';

    // Regex Patterns
    public const PATTERN_EMAIL_TEST = '/test@example\.com/';
    public const PATTERN_INVALID_UNCLOSED_BRACKET = '/invalid[/';
    public const PATTERN_TEST = '/test/';
    public const PATTERN_DIGITS = '/\d+/';
    public const PATTERN_SECRET = '/secret/';
    public const PATTERN_EMAIL_FULL = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
    public const PATTERN_RECURSIVE = '/(?R)/';
    public const PATTERN_NAMED_RECURSION = '/(?P>name)/';
    public const PATTERN_SSN_FORMAT = '/\d{3}-\d{2}-\d{4}/';

    // Field Paths
    public const FIELD_MESSAGE = 'message';
    public const FIELD_GENERIC = 'field';
    public const FIELD_USER_EMAIL = 'user.email';
    public const FIELD_USER_NAME = 'user.name';
    public const FIELD_USER_PUBLIC = 'user.public';
    public const FIELD_USER_PASSWORD = 'user.password';
    public const FIELD_SYSTEM_LOG = 'system.log';

    // Path Patterns
    public const PATH_USER_WILDCARD = 'user.*';

    // Test Data
    public const DATA_TEST = 'test';
    public const DATA_TEST_DATA = 'test data';
    public const DATA_MASKED = 'masked';

    // Replacement Values
    public const REPLACEMENT_TEST = '[TEST]';

    /**
     * Prevent instantiation.
     *
     * @psalm-suppress UnusedConstructor
     */
    private function __construct()
    {
    }
}
