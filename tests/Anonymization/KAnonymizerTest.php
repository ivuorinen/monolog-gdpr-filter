<?php

declare(strict_types=1);

namespace Tests\Anonymization;

use Ivuorinen\MonologGdprFilter\Anonymization\GeneralizationStrategy;
use Ivuorinen\MonologGdprFilter\Anonymization\KAnonymizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\TestConstants;

#[CoversClass(KAnonymizer::class)]
#[CoversClass(GeneralizationStrategy::class)]
final class KAnonymizerTest extends TestCase
{
    public function testAnonymizeWithAgeStrategy(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age');

        $record = ['name' => 'John', 'age' => 25];
        $result = $anonymizer->anonymize($record);

        $this->assertSame(TestConstants::AGE_RANGE_20_29, $result['age']);
        $this->assertSame('John', $result['name']);
    }

    public function testAnonymizeWithAgeStrategyDifferentRanges(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age', 5);

        $this->assertSame('20-24', $anonymizer->anonymize(['age' => 22])['age']);
        $this->assertSame('25-29', $anonymizer->anonymize(['age' => 27])['age']);
    }

    public function testAnonymizeWithDateStrategyMonth(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerDateStrategy('created_at', 'month');

        $record = ['created_at' => '2024-03-15'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('2024-03', $result['created_at']);
    }

    public function testAnonymizeWithDateStrategyYear(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerDateStrategy('birth_date', 'year');

        $record = ['birth_date' => '1990-05-20'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('1990', $result['birth_date']);
    }

    public function testAnonymizeWithDateStrategyQuarter(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerDateStrategy('quarter_date', 'quarter');

        $this->assertSame('2024-Q1', $anonymizer->anonymize(['quarter_date' => '2024-02-15'])['quarter_date']);
        $this->assertSame('2024-Q2', $anonymizer->anonymize(['quarter_date' => '2024-05-15'])['quarter_date']);
        $this->assertSame('2024-Q3', $anonymizer->anonymize(['quarter_date' => '2024-08-15'])['quarter_date']);
        $this->assertSame('2024-Q4', $anonymizer->anonymize(['quarter_date' => '2024-11-15'])['quarter_date']);
    }

    public function testAnonymizeWithDateTimeObject(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerDateStrategy('date', 'month');

        $record = ['date' => new \DateTimeImmutable('2024-06-15')];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('2024-06', $result['date']);
    }

    public function testAnonymizeWithLocationStrategy(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerLocationStrategy('zip_code', 3);

        $record = ['zip_code' => '12345'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('123**', $result['zip_code']);
    }

    public function testAnonymizeWithLocationStrategyShortValue(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerLocationStrategy('zip', 5);

        $record = ['zip' => '123'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('123', $result['zip']);
    }

    public function testAnonymizeWithNumericRangeStrategy(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerNumericRangeStrategy('salary', 1000);

        $record = ['salary' => 52500];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('52000-52999', $result['salary']);
    }

    public function testAnonymizeWithCustomStrategy(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerCustomStrategy('email', fn(mixed $v): string => explode('@', (string) $v)[1] ?? 'unknown');

        $record = ['email' => 'john@example.com'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('example.com', $result['email']);
    }

    public function testRegisterStrategy(): void
    {
        $strategy = new GeneralizationStrategy(fn(mixed $v): string => 'masked', 'test');

        $anonymizer = new KAnonymizer();
        $anonymizer->registerStrategy('field', $strategy);

        $record = ['field' => 'value'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame('masked', $result['field']);
    }

    public function testAnonymizeIgnoresMissingFields(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age');

        $record = ['name' => 'John'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame(['name' => 'John'], $result);
    }

    public function testAnonymizeBatch(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age');

        $records = [
            ['name' => 'John', 'age' => 25],
            ['name' => 'Jane', 'age' => 32],
        ];

        $results = $anonymizer->anonymizeBatch($records);

        $this->assertCount(2, $results);
        $this->assertSame(TestConstants::AGE_RANGE_20_29, $results[0]['age']);
        $this->assertSame('30-39', $results[1]['age']);
    }

    public function testAnonymizeWithAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path, mixed $original, mixed $masked) use (&$logs): void {
            $logs[] = ['path' => $path, 'original' => $original, TestConstants::DATA_MASKED => $masked];
        };

        $anonymizer = new KAnonymizer($auditLogger);
        $anonymizer->registerAgeStrategy('age');

        $anonymizer->anonymize(['age' => 25]);

        $this->assertCount(1, $logs);
        $this->assertSame('k-anonymity.age', $logs[0]['path']);
        $this->assertSame(25, $logs[0]['original']);
        $this->assertSame(TestConstants::AGE_RANGE_20_29, $logs[0][TestConstants::DATA_MASKED]);
    }

    public function testSetAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $anonymizer = new KAnonymizer();
        $anonymizer->setAuditLogger($auditLogger);
        $anonymizer->registerAgeStrategy('age');

        $anonymizer->anonymize(['age' => 25]);

        $this->assertNotEmpty($logs);
    }

    public function testGetStrategies(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age');
        $anonymizer->registerLocationStrategy('zip', 3);

        $strategies = $anonymizer->getStrategies();

        $this->assertCount(2, $strategies);
        $this->assertArrayHasKey('age', $strategies);
        $this->assertArrayHasKey('zip', $strategies);
    }

    public function testCreateGdprDefault(): void
    {
        $anonymizer = KAnonymizer::createGdprDefault();

        $strategies = $anonymizer->getStrategies();

        $this->assertArrayHasKey('age', $strategies);
        $this->assertArrayHasKey('birth_date', $strategies);
        $this->assertArrayHasKey('created_at', $strategies);
        $this->assertArrayHasKey('zip_code', $strategies);
        $this->assertArrayHasKey('postal_code', $strategies);
    }

    public function testCreateGdprDefaultWithAuditLogger(): void
    {
        $logs = [];
        $auditLogger = function (string $path) use (&$logs): void {
            $logs[] = ['path' => $path];
        };

        $anonymizer = KAnonymizer::createGdprDefault($auditLogger);
        $anonymizer->anonymize(['age' => 35]);

        $this->assertNotEmpty($logs);
    }

    public function testGeneralizationStrategyGetType(): void
    {
        $strategy = new GeneralizationStrategy(fn(mixed $v): string => (string) $v, 'test_type');

        $this->assertSame('test_type', $strategy->getType());
    }

    public function testGeneralizationStrategyGeneralize(): void
    {
        $strategy = new GeneralizationStrategy(fn(mixed $v): string => strtoupper((string) $v));

        $this->assertSame('HELLO', $strategy->generalize('hello'));
    }

    public function testMultipleStrategiesOnSameRecord(): void
    {
        $anonymizer = new KAnonymizer();
        $anonymizer->registerAgeStrategy('age');
        $anonymizer->registerLocationStrategy('zip', 2);
        $anonymizer->registerDateStrategy('date', 'year');

        $record = ['age' => 28, 'zip' => '12345', 'date' => '2024-06-15', 'name' => 'John'];
        $result = $anonymizer->anonymize($record);

        $this->assertSame(TestConstants::AGE_RANGE_20_29, $result['age']);
        $this->assertSame('12***', $result['zip']);
        $this->assertSame('2024', $result['date']);
        $this->assertSame('John', $result['name']);
    }

    public function testFluentInterface(): void
    {
        $anonymizer = (new KAnonymizer())
            ->registerAgeStrategy('age')
            ->registerLocationStrategy('zip', 3)
            ->registerDateStrategy('date', 'month');

        $this->assertCount(3, $anonymizer->getStrategies());
    }
}
