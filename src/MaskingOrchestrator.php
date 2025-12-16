<?php

declare(strict_types=1);

namespace Ivuorinen\MonologGdprFilter;

use Error;
use Ivuorinen\MonologGdprFilter\ArrayAccessor\ArrayAccessorFactory;

/**
 * Coordinates masking operations across different processors.
 *
 * This class orchestrates the masking workflow:
 * 1. Applies regex patterns to messages
 * 2. Processes field paths in context data
 * 3. Executes custom callbacks
 * 4. Applies data type masking
 *
 * Separated from GdprProcessor to enable use outside Monolog context.
 *
 * @api
 */
final class MaskingOrchestrator
{
    private readonly DataTypeMasker $dataTypeMasker;
    private readonly JsonMasker $jsonMasker;
    private readonly ContextProcessor $contextProcessor;
    private readonly RecursiveProcessor $recursiveProcessor;
    private readonly ArrayAccessorFactory $arrayAccessorFactory;

    /**
     * @var callable(string,mixed,mixed):void|null
     */
    private $auditLogger;

    /**
     * @param array<string,string> $patterns Regex pattern => replacement
     * @param array<string,FieldMaskConfig|string> $fieldPaths Dot-notation path => FieldMaskConfig
     * @param array<string,callable(mixed):string> $customCallbacks Dot-notation path => callback(value): string
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger callback
     * @param int $maxDepth Maximum recursion depth for nested structures
     * @param array<string,string> $dataTypeMasks Type-based masking: type => mask pattern
     * @param ArrayAccessorFactory|null $arrayAccessorFactory Factory for creating array accessors
     */
    public function __construct(
        private readonly array $patterns,
        private readonly array $fieldPaths = [],
        private readonly array $customCallbacks = [],
        ?callable $auditLogger = null,
        int $maxDepth = 100,
        array $dataTypeMasks = [],
        ?ArrayAccessorFactory $arrayAccessorFactory = null
    ) {
        $this->auditLogger = $auditLogger;
        $this->arrayAccessorFactory = $arrayAccessorFactory ?? ArrayAccessorFactory::default();

        // Initialize data type masker
        $this->dataTypeMasker = new DataTypeMasker($dataTypeMasks, $auditLogger);

        // Initialize recursive processor for data structure processing
        $this->recursiveProcessor = new RecursiveProcessor(
            $this->regExpMessage(...),
            $this->dataTypeMasker,
            $auditLogger,
            $maxDepth
        );

        // Initialize JSON masker with recursive mask callback
        /** @psalm-suppress InvalidArgument - recursiveMask is intentionally impure due to audit logging */
        $this->jsonMasker = new JsonMasker(
            $this->recursiveProcessor->recursiveMask(...),
            $auditLogger
        );

        // Initialize context processor for field-level operations
        $this->contextProcessor = new ContextProcessor(
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $this->regExpMessage(...)
        );
    }

    /**
     * Create an orchestrator with validated parameters.
     *
     * @param array<string,string> $patterns Regex pattern => replacement
     * @param array<string,FieldMaskConfig|string> $fieldPaths Dot-notation path => FieldMaskConfig
     * @param array<string,callable(mixed):string> $customCallbacks Dot-notation path => callback
     * @param callable(string,mixed,mixed):void|null $auditLogger Optional audit logger callback
     * @param int $maxDepth Maximum recursion depth for nested structures
     * @param array<string,string> $dataTypeMasks Type-based masking
     * @param ArrayAccessorFactory|null $arrayAccessorFactory Factory for creating array accessors
     *
     * @throws \InvalidArgumentException When any parameter is invalid
     */
    public static function create(
        array $patterns,
        array $fieldPaths = [],
        array $customCallbacks = [],
        ?callable $auditLogger = null,
        int $maxDepth = 100,
        array $dataTypeMasks = [],
        ?ArrayAccessorFactory $arrayAccessorFactory = null
    ): self {
        // Validate all parameters
        InputValidator::validateAll(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            []
        );

        // Pre-validate and cache patterns for better performance
        PatternValidator::cachePatterns($patterns);

        return new self(
            $patterns,
            $fieldPaths,
            $customCallbacks,
            $auditLogger,
            $maxDepth,
            $dataTypeMasks,
            $arrayAccessorFactory
        );
    }

    /**
     * Process data by masking sensitive information.
     *
     * @param string $message The message to mask
     * @param array<string,mixed> $context The context data to mask
     * @return array{message: string, context: array<string,mixed>}
     */
    public function process(string $message, array $context): array
    {
        $maskedMessage = $this->regExpMessage($message);
        $maskedContext = $this->processContext($context);

        return [
            'message' => $maskedMessage,
            'context' => $maskedContext,
        ];
    }

    /**
     * Process context data by masking sensitive information.
     *
     * @param array<string,mixed> $context The context data to mask
     * @return array<string,mixed>
     */
    public function processContext(array $context): array
    {
        $accessor = $this->arrayAccessorFactory->create($context);
        $processedFields = [];

        if ($this->fieldPaths !== []) {
            $processedFields = array_merge($processedFields, $this->contextProcessor->maskFieldPaths($accessor));
        }

        if ($this->customCallbacks !== []) {
            $processedFields = array_merge(
                $processedFields,
                $this->contextProcessor->processCustomCallbacks($accessor)
            );
        }

        if ($this->fieldPaths !== [] || $this->customCallbacks !== []) {
            $context = $accessor->all();
            // Apply data type masking to the entire context after field/callback processing
            return $this->dataTypeMasker->applyToContext(
                $context,
                $processedFields,
                '',
                $this->recursiveProcessor->recursiveMask(...)
            );
        }

        return $this->recursiveProcessor->recursiveMask($context, 0);
    }

    /**
     * Mask a string using all regex patterns with JSON support.
     */
    public function regExpMessage(string $message = ''): string
    {
        // Early return for empty messages
        if ($message === '') {
            return $message;
        }

        // Track original message for empty result protection
        $originalMessage = $message;

        // Handle JSON strings and regular patterns in a coordinated way
        $message = $this->maskMessageWithJsonSupport($message);

        return $message === '' || $message === '0' ? $originalMessage : $message;
    }

    /**
     * Mask message content, handling both JSON structures and regular patterns.
     */
    private function maskMessageWithJsonSupport(string $message): string
    {
        // Use JsonMasker to process JSON structures
        $result = $this->jsonMasker->processMessage($message);

        // Now apply regular patterns to the entire result
        foreach ($this->patterns as $regex => $replacement) {
            try {
                /** @psalm-suppress ArgumentTypeCoercion */
                $newResult = preg_replace($regex, $replacement, $result, -1, $count);

                if ($newResult === null) {
                    $error = preg_last_error_msg();

                    if ($this->auditLogger !== null) {
                        ($this->auditLogger)('preg_replace_error', $result, 'Error: ' . $error);
                    }

                    continue;
                }

                if ($count > 0) {
                    $result = $newResult;
                }
            } catch (Error $e) {
                if ($this->auditLogger !== null) {
                    ($this->auditLogger)('regex_error', $regex, $e->getMessage());
                }

                continue;
            }
        }

        return $result;
    }

    /**
     * Recursively mask all string values in an array using regex patterns.
     *
     * @param array<mixed>|string $data
     * @param int $currentDepth Current recursion depth
     * @return array<mixed>|string
     */
    public function recursiveMask(array|string $data, int $currentDepth = 0): array|string
    {
        return $this->recursiveProcessor->recursiveMask($data, $currentDepth);
    }

    /**
     * Get the context processor for direct access.
     */
    public function getContextProcessor(): ContextProcessor
    {
        return $this->contextProcessor;
    }

    /**
     * Get the recursive processor for direct access.
     */
    public function getRecursiveProcessor(): RecursiveProcessor
    {
        return $this->recursiveProcessor;
    }

    /**
     * Get the array accessor factory.
     */
    public function getArrayAccessorFactory(): ArrayAccessorFactory
    {
        return $this->arrayAccessorFactory;
    }

    /**
     * Set the audit logger callable.
     *
     * @param callable(string,mixed,mixed):void|null $auditLogger
     */
    public function setAuditLogger(?callable $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
        $this->contextProcessor->setAuditLogger($auditLogger);
        $this->recursiveProcessor->setAuditLogger($auditLogger);
    }
}
