<?php

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Ivuorinen\MonologGdprFilter\FieldMaskConfig;
use Ivuorinen\MonologGdprFilter\GdprProcessor;

/**
 * GDPR Default Patterns Test
 *
 * @api
 */
#[CoversClass(FieldMaskConfig::class)]
#[CoversMethod(GdprProcessor::class, 'getDefaultPatterns')]
class GdprDefaultPatternsTest extends TestCase
{
    public function testPatternIban(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        // Finnish IBAN with spaces
        $iban = 'FI21 1234 5600 0007 85';
        $masked = $processor->maskMessage($iban);
        $this->assertSame('***IBAN***', $masked);
        // Finnish IBAN without spaces
        $ibanWithoutSpaces = 'FI2112345600000785';
        $this->assertSame('***IBAN***', $processor->maskMessage($ibanWithoutSpaces));
        $this->assertNotSame($ibanWithoutSpaces, $processor->maskMessage($ibanWithoutSpaces));

        // Edge: not an IBAN
        $notIban = 'FI21 1234 5600 000 85A';
        $this->assertSame($notIban, $processor->maskMessage($notIban));
    }

    public function testPatternPhone(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $phone = '+358 40 1234567';
        $masked = $processor->maskMessage($phone);
        $this->assertSame('***PHONE***', $masked);
        // Edge: not a phone
        $notPhone = 'Call me maybe';
        $this->assertSame($notPhone, $processor->maskMessage($notPhone));
    }

    public function testPatternUsSsn(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $ssn = '123-45-6789';
        $masked = $processor->maskMessage($ssn);
        $this->assertSame('***USSSN***', $masked);
        // Edge: not a SSN
        $notSsn = '123456789';
        $this->assertSame($notSsn, $processor->maskMessage($notSsn));
    }

    public function testPatternDob(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $dob1 = '1990-12-31';
        $dob2 = '31/12/1990';
        $masked1 = $processor->maskMessage($dob1);
        $masked2 = $processor->maskMessage($dob2);
        $this->assertSame('***DOB***', $masked1);
        $this->assertSame('***DOB***', $masked2);
        // Edge: not a DOB
        $notDob = '1990/31/12';
        $this->assertSame($notDob, $processor->maskMessage($notDob));
    }

    public function testPatternPassport(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $passport = 'A123456';
        $masked = $processor->maskMessage($passport);
        $this->assertSame('***PASSPORT***', $masked);
        // Edge: too short
        $notPassport = 'A1234';
        $this->assertSame($notPassport, $processor->maskMessage($notPassport));
    }


    public function testPatternCreditCard(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $cc1 = '4111 1111 1111 1111'; // Visa
        $cc2 = '5500-0000-0000-0004'; // MasterCard
        $cc3 = '340000000000009'; // Amex (15 digits)
        $cc4 = '6011000000000004'; // Discover
        $masked1 = $processor->maskMessage($cc1);
        $masked2 = $processor->maskMessage($cc2);
        $masked3 = $processor->maskMessage($cc3);
        $masked4 = $processor->maskMessage($cc4);
        $this->assertSame('***CC***', $masked1);
        $this->assertSame('***CC***', $masked2);
        $this->assertSame('***CC***', $masked3);
        $this->assertSame('***CC***', $masked4);
        // Edge: not a CC
        $notCc = '1234 5678 9012';
        $this->assertSame($notCc, $processor->maskMessage($notCc));
    }

    public function testPatternBearerToken(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $token = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
        $masked = $processor->maskMessage($token);
        $this->assertSame('***TOKEN***', $masked);
        // Edge: not a token
        $notToken = 'bearer token';
        $this->assertSame($notToken, $processor->maskMessage($notToken));
    }

    public function testPatternApiKey(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $apiKey = 'sk_test_4eC39HqLyjWDarj';
        $masked = $processor->maskMessage($apiKey);
        $this->assertSame('***APIKEY***', $masked);
        // Edge: short string
        $notApiKey = 'shortkey';
        $this->assertSame($notApiKey, $processor->maskMessage($notApiKey));
    }

    public function testPatternMac(): void
    {
        $patterns = GdprProcessor::getDefaultPatterns();
        $processor = new GdprProcessor($patterns);
        $mac = '00:1A:2B:3C:4D:5E';
        $masked = $processor->maskMessage($mac);
        $this->assertSame('***MAC***', $masked);
        $mac2 = '00-1A-2B-3C-4D-5E';
        $masked2 = $processor->maskMessage($mac2);
        $this->assertSame('***MAC***', $masked2);
        // Edge: not a MAC
        $notMac = '001A2B3C4D5E';
        $this->assertSame($notMac, $processor->maskMessage($notMac));
    }
}
