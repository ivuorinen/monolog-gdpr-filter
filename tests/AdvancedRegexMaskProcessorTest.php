<?php

declare(strict_types=1);

namespace Tests;

use Tests\TestConstants;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for advanced regex masking processor.
 *
 * @api
 */
#[CoversClass(GdprProcessor::class)]
class AdvancedRegexMaskProcessorTest extends TestCase
{
    use TestHelpers;

    private GdprProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $patterns = [
            "/\b\d{6}[-+A]?\d{3}[A-Z]\b/u" => MaskConstants::MASK_HETU,
            "/\b[0-9]{16}\b/u" => MaskConstants::MASK_CC,
            "/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/" => MaskConstants::MASK_EMAIL,
        ];

        $fieldPaths = [
            "user.ssn" => "[GDPR]",
            "payment.card" => "[CC]",
            "contact.email" => FieldMaskConfig::useProcessorPatterns(), // use regex-masked
            "metadata.session" => "[SESSION]",
        ];

        $this->processor = new GdprProcessor($patterns, $fieldPaths);
    }

    public function testMaskCreditCardInMessage(): void
    {
        $record = $this->logEntry()->with(message: "Card: 1234567812345678");
        $result = ($this->processor)($record)->toArray();
        $this->assertSame("Card: " . MaskConstants::MASK_CC, $result["message"]);
    }

    public function testMaskEmailInMessage(): void
    {
        $record = $this->logEntry()->with(message: "Email: user@example.com");

        $result = ($this->processor)($record)->toArray();
        $this->assertSame("Email: " . MaskConstants::MASK_EMAIL, $result["message"]);
    }

    public function testContextFieldPathReplacements(): void
    {
        $record = $this->logEntry()->with(
            message: "Mixed data",
            context: [
                "user" => ["ssn" => self::TEST_HETU],
                "payment" => ["card" => self::TEST_CC],
                "contact" => [TestConstants::CONTEXT_EMAIL => self::TEST_EMAIL],
                "metadata" => ["session" => "abc123xyz"],
            ],
            extra: [],
        );

        $result = ($this->processor)($record)->toArray();

        $this->assertSame("[GDPR]", $result["context"]["user"]["ssn"]);
        $this->assertSame("[CC]", $result["context"]["payment"]["card"]);
        // empty replacement uses regex-masked value
        $this->assertSame(MaskConstants::MASK_EMAIL, $result["context"]["contact"][TestConstants::CONTEXT_EMAIL]);
        $this->assertSame("[SESSION]", $result["context"]["metadata"]["session"]);
    }
}
