<?php

declare(strict_types=1);

namespace Tests;

use Ivuorinen\MonologGdprFilter\GdprProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdprProcessor::class)]
class AdvancedRegexMaskProcessorTest extends TestCase
{
    use TestHelpers;

    private GdprProcessor $processor;

    /**
     * @psalm-suppress MissingOverrideAttribute
     */
    protected function setUp(): void
    {
        parent::setUp();

        $patterns = [
            "/\b\d{6}[-+A]?\d{3}[A-Z]\b/u" => "***HETU***",
            "/\b[0-9]{16}\b/u" => "***CC***",
            "/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/" => "***EMAIL***",
        ];

        $fieldPaths = [
            "user.ssn" => "[GDPR]",
            "payment.card" => "[CC]",
            "contact.email" => GdprProcessor::maskWithRegex(), // use regex-masked
            "metadata.session" => "[SESSION]",
        ];

        $this->processor = new GdprProcessor($patterns, $fieldPaths);
    }

    public function testMaskCreditCardInMessage(): void
    {
        $record = $this->logEntry()->with(message: "Card: 1234567812345678");
        $result = ($this->processor)($record);
        $this->assertSame("Card: ***CC***", $result["message"]);
    }

    public function testMaskEmailInMessage(): void
    {
        $record = $this->logEntry()->with(message: "Email: user@example.com");

        $result = ($this->processor)($record);
        $this->assertSame("Email: ***EMAIL***", $result["message"]);
    }

    public function testContextFieldPathReplacements(): void
    {
        $record = $this->logEntry()->with(
            message: "Mixed data",
            context: [
                "user" => ["ssn" => self::TEST_HETU],
                "payment" => ["card" => self::TEST_CC],
                "contact" => ["email" => self::TEST_EMAIL],
                "metadata" => ["session" => "abc123xyz"],
            ],
            extra: [],
        );

        $result = ($this->processor)($record);

        $this->assertSame("[GDPR]", $result["context"]["user"]["ssn"]);
        $this->assertSame("[CC]", $result["context"]["payment"]["card"]);
        // empty replacement uses regex-masked value
        $this->assertSame("***EMAIL***", $result["context"]["contact"]["email"]);
        $this->assertSame("[SESSION]", $result["context"]["metadata"]["session"]);
    }
}
