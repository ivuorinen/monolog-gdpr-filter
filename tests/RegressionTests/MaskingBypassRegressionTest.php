<?php

declare(strict_types=1);

namespace Tests\RegressionTests;

use DateTimeImmutable;
use Ivuorinen\MonologGdprFilter\DefaultPatterns;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;
use Ivuorinen\MonologGdprFilter\MaskConstants as Mask;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

/**
 * Regressions for masking bypasses found by audit: every test here asserts that
 * sensitive data does NOT survive processing.
 */
#[CoversClass(GdprProcessor::class)]
#[CoversClass(DefaultPatterns::class)]
final class MaskingBypassRegressionTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context
        );
    }

    /**
     * Configuring a fieldPath must not disable regex masking of the other context
     * values (the fieldPaths branch used to skip recursive masking entirely).
     */
    #[Test]
    public function testFieldPathConfigDoesNotDisableRegexMaskingOfOtherFields(): void
    {
        $processor = new GdprProcessor(
            patterns: [TestConstants::PATTERN_EMAIL_SIMPLE => Mask::MASK_EMAIL],
            fieldPaths: [TestConstants::CONTEXT_PASSWORD => FieldMaskConfig::remove()],
        );

        $result = $processor($this->record('x', [
            'note' => 'mail me at john@example.com',
            TestConstants::CONTEXT_PASSWORD => 'hunter2',
            'deep' => ['inner' => 'bob@example.org'],
        ]));

        $this->assertSame('mail me at ' . Mask::MASK_EMAIL, $result->context['note']);
        $this->assertSame(Mask::MASK_EMAIL, $result->context['deep']['inner']);
        $this->assertArrayNotHasKey(TestConstants::CONTEXT_PASSWORD, $result->context);
    }

    /**
     * The 'recursive' array mask used to restart the depth counter at 0 on every
     * level, so maxDepth never tripped.
     */
    #[Test]
    public function testRecursiveArrayMaskCannotBypassDepthGuard(): void
    {
        $deep = [];
        $cursor = &$deep;
        for ($i = 0; $i < 200; $i++) {
            $cursor['level'] = [];
            $cursor = &$cursor['level'];
        }

        unset($cursor);

        $depthReached = [];
        $processor = new GdprProcessor(
            patterns: [],
            auditLogger: function (string $path, mixed $original, mixed $masked) use (&$depthReached): void {
                if ($path === 'max_depth_reached') {
                    $depthReached[] = $original;
                }
            },
            maxDepth: 10,
            dataTypeMasks: ['array' => 'recursive'],
        );

        $processor($this->record('x', $deep));

        $this->assertNotSame([], $depthReached, 'Depth guard never fired');
        $this->assertSame([10], array_values(array_unique($depthReached)));
    }

    /**
     * A malformed UTF-8 byte makes PCRE abort every /u pattern. The value must not
     * be passed through unmasked when that happens.
     */
    #[Test]
    public function testMalformedUtf8DoesNotDisableMasking(): void
    {
        $processor = new GdprProcessor(patterns: DefaultPatterns::get());
        $message = "card 4111111111111111 raw=\xC3\x28 end";

        $result = $processor($this->record($message, ['note' => "card 4111111111111111 \xC3\x28"]));

        $this->assertStringNotContainsString('4111111111111111', $result->message);
        $this->assertStringContainsString(Mask::MASK_CC, $result->message);
        $this->assertStringNotContainsString('4111111111111111', (string) $result->context['note']);
    }

    /**
     * Patterns are applied one at a time, so a pattern that fails at runtime must
     * not discard the masking already done by the others.
     */
    #[Test]
    public function testFailingPatternDoesNotVoidOtherPatterns(): void
    {
        $processor = new GdprProcessor(patterns: [
            // /u pattern that aborts on the malformed byte in the subject
            '/\bFI\d{16}\b/u' => Mask::MASK_IBAN,
            TestConstants::PATTERN_EMAIL_SIMPLE => Mask::MASK_EMAIL,
        ]);

        $masked = $processor->maskMessage("mail john@example.com raw=\xC3\x28");

        $this->assertStringNotContainsString(TestConstants::EMAIL_JOHN, $masked);
        $this->assertStringContainsString(Mask::MASK_EMAIL, $masked);
    }

    /**
     * Default patterns must match sensitive values embedded in a message, not only
     * when the value is the whole string.
     *
     * Each case gives a sprintf template plus the fixture value, so the surrounding
     * sentence and the value asserted absent are built from the same constant. Spelling
     * the value inline twice would let them drift, and the "must not contain" assertion
     * would then pass for the wrong reason.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function embeddedValueProvider(): array
    {
        return [
            'email' => ['user %s logged in', TestConstants::EMAIL_JOHN_DOE, Mask::MASK_EMAIL],
            'phone' => ['called %s today', TestConstants::PHONE_INTL, Mask::MASK_PHONE],
            'iban' => ['paid to %s ok', TestConstants::IBAN_FI, Mask::MASK_IBAN],
            'us ssn' => ['ssn is %s here', TestConstants::SSN_US, Mask::MASK_USSSN],
            'bearer' => ['auth Bearer %s ok', TestConstants::BEARER_TOKEN, Mask::MASK_TOKEN],
            'dob' => ['born %s in Turku', TestConstants::DOB, Mask::MASK_DOB],
            'passport' => ['passport %s issued', TestConstants::PASSPORT, Mask::MASK_PASSPORT],
            'mac' => ['nic %s up', TestConstants::MAC_ADDRESS, Mask::MASK_MAC],
            'hetu' => ['hetu %s logged', TestConstants::HETU, Mask::MASK_HETU],
        ];
    }

    #[DataProvider('embeddedValueProvider')]
    #[Test]
    public function testDefaultPatternsMaskValuesEmbeddedInAMessage(
        string $template,
        string $sensitive,
        string $expectedMask
    ): void {
        $message = sprintf($template, $sensitive);
        $masked = (new GdprProcessor(patterns: DefaultPatterns::get()))->maskMessage($message);

        // Guard the guard: the fixture must actually be present before masking, otherwise
        // the assertion below would pass against a template that never contained it.
        $this->assertStringContainsString($sensitive, $message);
        $this->assertStringNotContainsString($sensitive, $masked);
        $this->assertStringContainsString($expectedMask, $masked, 'Masked under the wrong label');
    }

    /**
     * Empty objects used to be restored by rewriting the first N `[]` occurrences in the
     * encoded JSON, which also rewrote `[]` appearing inside string values.
     *
     * Every input is deliberately written with whitespace between tokens. Only the JSON
     * path decodes and re-encodes, which normalises that whitespace away, so a compact
     * expectation cannot be satisfied by a plain regex substitution over the raw string.
     * That is what makes these cases actually exercise restoreObjectShape().
     *
     * @return array<string, array{string, string}>
     */
    public static function jsonShapeProvider(): array
    {
        return [
            'empty object preserved' => [
                '{"meta": {}, "v": "secret"}',
                '{"meta":{},"v":"***"}',
            ],
            'bracket inside a string' => [
                '{"note": "array literal [] here", "meta": {}, "v": "secret"}',
                '{"note":"array literal [] here","meta":{},"v":"***"}',
            ],
            'nested empty objects' => [
                '{"a": {}, "b": {"c": {}}, "v": "secret"}',
                '{"a":{},"b":{"c":{}},"v":"***"}',
            ],
            'empty array stays an array' => [
                '{"list": [], "meta": {}, "v": "secret"}',
                '{"list":[],"meta":{},"v":"***"}',
            ],
        ];
    }

    /**
     * Uses regExpMessage(), not maskMessage(): only regExpMessage() runs
     * maskMessageWithJsonSupport(), so it is the sole entry point that reaches JsonMasker.
     * maskMessage() is a plain per-pattern preg_replace loop, and asserting against it
     * would pass even with restoreObjectShape() removed entirely.
     */
    #[Test]
    #[DataProvider('jsonShapeProvider')]
    public function jsonMaskingPreservesStructureWithoutCorruptingStrings(
        string $json,
        string $expected
    ): void {
        $processor = new GdprProcessor(patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_GENERIC]);

        $this->assertSame($expected, $processor->regExpMessage($json));
    }

    /**
     * Guards the guard: proves the provider above is routed through the JSON path rather
     * than satisfied by raw string substitution. maskMessage() never decodes, so the
     * whitespace survives and its output differs from the re-encoded form.
     */
    #[Test]
    public function jsonShapeCasesAreNotSatisfiedByPlainSubstitution(): void
    {
        $processor = new GdprProcessor(patterns: [TestConstants::PATTERN_SECRET => Mask::MASK_GENERIC]);
        $json = '{"meta": {}, "v": "secret"}';

        $this->assertSame('{"meta":{},"v":"***"}', $processor->regExpMessage($json));
        $this->assertSame('{"meta": {}, "v": "***"}', $processor->maskMessage($json));
    }

    /**
     * These eight default patterns previously had no test anywhere, so a typo in any of
     * them would have leaked unmasked PII silently. Each case gives a value that must be
     * masked and a near-miss that must not be.
     *
     * Same template/value split as embeddedValueProvider, which additionally lets this
     * test assert the raw value is *gone* rather than only that the mask label appeared.
     * These fixtures have no TestConstants entries; they are single-use values whose
     * exact shape is the point of the case, so they stay inline.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function uncoveredDefaultPatternProvider(): array
    {
        return [
            // [sentence template, sensitive value, expected mask, near-miss left untouched]
            'vehicle plate' => ['plate %s seen', 'ABC-1234', Mask::MASK_VEHICLE, 'plate AB seen'],
            'vehicle reverse' => ['plate %s seen', '123-ABC', Mask::MASK_VEHICLE, 'plate 12 seen'],
            'uk national insurance' => ['ni %s on file', 'AB123456C', Mask::MASK_UKNI, 'ni AB12345 on file'],
            'canadian sin' => ['sin %s filed', '123-456-789', Mask::MASK_CASIN, 'sin 123-456 filed'],
            'uk bank' => ['acct %s ok', '123456-12345678', Mask::MASK_UKBANK, 'acct 1234-5678 ok'],
            'canadian bank' => ['acct %s ok', '12345-1234567', Mask::MASK_CABANK, 'acct 1234-123 ok'],
            'medicare' => ['medicare %s ok', '123 45 6789', Mask::MASK_MEDICARE, 'medicare 12 34 ok'],
            'ehic' => ['ehic %s ok', '12-3456-7890-1234-5', Mask::MASK_EHIC, 'ehic 12-3456 ok'],
            'ipv6' => ['addr %s up', '2001:0db8:85a3:0000:0000:8a2e', '***IPv6***', 'addr 2001 up'],
        ];
    }

    #[DataProvider('uncoveredDefaultPatternProvider')]
    #[Test]
    public function testPreviouslyUncoveredDefaultPatternsMaskAndDoNotOvermatch(
        string $template,
        string $sensitive,
        string $expectedMask,
        string $nearMiss
    ): void {
        $processor = new GdprProcessor(patterns: DefaultPatterns::get());
        $masked = $processor->maskMessage(sprintf($template, $sensitive));

        $this->assertStringContainsString(
            $expectedMask,
            $masked,
            'Sensitive value was not masked under the expected label'
        );
        $this->assertStringNotContainsString($sensitive, $masked, 'Sensitive value survived masking');
        $this->assertSame(
            $nearMiss,
            $processor->maskMessage($nearMiss),
            'Near-miss value must not be masked'
        );
    }

    /**
     * regExpMessage() used to return the ORIGINAL message whenever the masked result
     * was '' or '0', which re-emitted the unmasked value for any pattern that redacts
     * a message down to nothing.
     */
    #[Test]
    public function testFullyRedactedMessageIsNotReplacedByTheOriginal(): void
    {
        // A card number masked away entirely must not come back.
        $processor = new GdprProcessor(patterns: ['/^\d{16}$/' => '']);

        $result = $processor->regExpMessage('4111111111111111');

        $this->assertSame('', $result);
        $this->assertStringNotContainsString('4111111111111111', $result);
    }

    /**
     * The generic API-key heuristic matches any 20+ character token, so it must stay
     * whole-value anchored rather than eating ordinary words in a message.
     */
    #[Test]
    public function testGenericApiKeyHeuristicDoesNotMaskOrdinaryMessageText(): void
    {
        $processor = new GdprProcessor(patterns: DefaultPatterns::get());
        $sentence = 'deployment finished successfully without any errors';

        $this->assertSame($sentence, $processor->maskMessage($sentence));
        $this->assertSame(Mask::MASK_APIKEY, $processor->maskMessage('abcdefghijklmnopqrstuvwxyz'));
    }
}
