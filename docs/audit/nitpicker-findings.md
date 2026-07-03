# Nitpicker Findings

Generated: 2026-07-03
Last validated: 2026-07-03

## Summary

- Total: 46 | Open: 20 | Fixed: 25 | Invalid: 1

## Open Findings

### Critical

#### [NP-001] Regex masking silently skipped for context fields when any fieldPath/callback is configured

Category: correctness
Area: src/MaskingOrchestrator.php:155-183
Problem: When `fieldPaths` or `customCallbacks` are non-empty, `processContext` runs only
field-path/callback processing plus `dataTypeMasker->applyToContext`, never `recursiveMask`
over the remaining context values.
Evidence: With an email pattern + a `password => remove` fieldPath,
`{note: "mail me at john@example.com"}` passes through unmasked; it masks correctly when no
fieldPaths are set.
Impact: Adding a single field rule disables all regex masking of every other context value —
a GDPR data leak.
Fix: In the fieldPaths/callbacks branch, run each not-yet-processed field through
`recursiveProcessor->recursiveMask()` as the no-fieldPaths branch does.
Status note: Pre-existing defect, not introduced by PR #115. Behavioral fix + new tests
belong in a dedicated PR, not the PHP-upgrade branch.

#### [NP-002] Depth guard bypassable via DataTypeMasker 'recursive' → segfault on deep arrays

Category: reliability
Area: src/RecursiveProcessor.php:165-172
Problem: `processArrayValue` calls `dataTypeMasker->applyMasking`; with `array => 'recursive'`
configured, `maskArray` re-enters `recursiveMaskCallback($value, 0)`, resetting depth to 0 at
every level, so `maxDepth` never trips.
Evidence: 200k-deep nested array with `['array' => 'recursive']` → PHP segmentation fault
(exit 139) in PoC.
Impact: Remote-triggerable crash from attacker-controlled log context.
Fix: Thread the real current depth into the recursive-array mask (`$currentDepth + 1`);
enforce `maxDepth` on that path.
Status note: Pre-existing; same dedicated-PR scoping as NP-001.

### High

#### [NP-003] FieldMaskConfig::regexMask() custom pattern/replacement ignored by main processor

Category: correctness
Area: src/ContextProcessor.php:124-140, src/FieldMaskConfig.php:65-83
Problem: `MASK_REGEX` configs store `pattern::replacement`, but `ContextProcessor::maskValue`
applies only the processor's global patterns and never reads
`getRegexPattern()/getReplacement()`. Only `FieldPathMaskingStrategy` honors it, and that
class is not wired into `GdprProcessor`.
Evidence: `{card: 'card 1234'}` with `regexMask('/\d+/', 'NUM')` and no global patterns is
returned unchanged.
Impact: Users believe a field is masked when it is not.
Fix: In `maskValue`, when the config has a regex pattern, apply it directly via
`preg_replace`.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-004] Array field value under MASK_REGEX stringified to literal "Array"

Category: correctness
Area: src/ContextProcessor.php:127
Problem: The MASK_REGEX branch does `(string) $value` on arrays.
Evidence: `{user: ['email' => 'a@b.com']}` with a MASK_REGEX fieldPath emits an
"Array to string conversion" warning and stores the literal `'Array'`.
Impact: Nested data destroyed; masking intent silently lost.
Fix: Recurse into arrays (or `json_encode`) before applying regex, matching
`AbstractMaskingStrategy::valueToString`.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-005] JsonMasker::fixEmptyObjects corrupts string contents

Category: correctness
Area: src/JsonMasker.php:211-226
Problem: Blindly `preg_replace`s the first N `[]` occurrences in encoded JSON, counting `{}`
in the raw original, with no structural awareness.
Evidence: `{"a":"x[]y","b":{}}` → `{"a":"x{}y","b":[]}` — the `[]` inside a string value was
rewritten and the genuine empty object emitted as `[]`.
Impact: Any log payload containing `[]`/`{}` inside string values is silently mutated.
Fix: Decode with `json_decode($json, false)` to preserve empty-object identity instead of
regex surgery on encoded output.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-006] ReDoS security regression test cannot fail on a ReDoS regression

Category: tests
Area: tests/RegressionTests/SecurityRegressionTest.php:100-114
Problem: If `PatternValidator::validateAll()` stops rejecting a catastrophic-backtracking
pattern, the test calls `error_log()` and `assertTrue(true)`; the catch-all
`assertInstanceOf(Throwable::class, $e)` is tautological.
Evidence: Patterns like `/^(?=.*a)(?=.*b)(.*)+$/` are covered nowhere else; a validator
regression ships silently.
Impact: The DoS-protection promise of a GDPR library is untested.
Fix: Data provider asserting `InvalidRegexPatternException` per rejected pattern; separate
expected-accept test for tolerated patterns (mirror the fix applied to
CriticalBugRegressionTest in NP-031).

#### [NP-007] Eight default GDPR patterns have zero test coverage

Category: tests
Area: src/DefaultPatterns.php:57-78 vs tests/
Problem: Vehicle plates, UK NI, CA SIN, UK bank, CA bank, Medicare, EHIC, and IPv6 masks
have no test anywhere.
Evidence: `grep -ri 'MASK_VEHICLE|MASK_UKNI|MASK_MEDICARE|MASK_EHIC|medicare|IPv6' tests/`
returns no hits.
Impact: A regex typo in any of them leaks unmasked PII with no failing test.
Fix: Extend GdprDefaultPatternsTest with one positive and one negative case per default
pattern. New coverage may surface real pattern bugs — schedule with the dedicated fix PR.

### Medium

#### [NP-009] Throwing callback registered via fieldPaths escapes and aborts logging

Category: reliability
Area: src/ContextProcessor.php:115-147
Problem: `maskValue` invokes `customCallbacks[$path]` with no try/catch (only
`processCustomCallbacks` is guarded).
Evidence: A path present in both `fieldPaths` and `customCallbacks` with a throwing callback
propagates out of `__invoke`.
Impact: Log record lost; app may crash inside the logger.
Fix: Wrap the callback invocation in `maskValue` in try/catch mirroring
`processCustomCallbacks`.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-010] Callback runs twice when a path is in both customCallbacks and fieldPaths

Category: correctness
Area: src/ContextProcessor.php:118-122
Problem: `processContext` calls `maskFieldPaths` (which prefers the customCallback) and then
`processCustomCallbacks` (which runs it again).
Evidence: PoC shows callback invoked twice (`H(H(bob))`).
Impact: Non-idempotent callbacks double-apply; audit logs duplicate.
Fix: Process each path once; skip already-handled paths in `processCustomCallbacks`.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-011] Message masking discarded if the masked result is '' or '0'

Category: correctness
Area: src/MaskingOrchestrator.php:201, src/GdprProcessor.php (regExpMessage)
Problem: The original message is returned when the masked result is empty or `'0'`.
Evidence: Pattern `/secret-\d+/ => ''` leaves `secret-123` fully unmasked.
Impact: Legitimate redaction-to-empty leaks the sensitive original.
Fix: Track whether a replacement occurred (e.g. `preg_replace` count) instead of inferring
from the result string.
Status note: Pre-existing; dedicated-PR scope.

#### [NP-012] Duplicate coverage pipelines on identical triggers

Category: maintainability
Area: .github/workflows/test-coverage.yaml, .github/workflows/ci.yml:67-96
Problem: Both run `composer test:ci` producing coverage.xml on push/PR to main.
Evidence: Two full test runs per event with two different consumers (Codecov vs
artifact+PR comment).
Impact: Doubled CI time; drift between two coverage sources of truth.
Fix: Merge into one workflow; run tests once, feed both consumers from the same coverage.xml.
Status note: Workflow-consolidation design choice left to the maintainer.

#### [NP-013] Security tests accept every outcome (malicious-pattern constructor test)

Category: tests
Area: tests/RegressionTests/SecurityRegressionTest.php:364-382
Problem: For each malicious pattern, both "constructor accepts" (`assertTrue(true)`) and
"constructor throws" (tautological assertInstanceOf) pass.
Evidence: Test cannot fail regardless of validation behavior.
Impact: Injection-style patterns reaching production config are never caught here.
Fix: Split into expected-reject / expected-accept lists with explicit assertions.

#### [NP-014] 16+ assertTrue(true) "does not throw" tests assert nothing observable

Category: tests
Area: tests/InputValidatorTest.php (9 sites), tests/PatternValidatorTest.php:143,386,
tests/GdprProcessorTest.php:373, tests/GdprProcessorComprehensiveTest.php:181,
tests/RegressionTests/ComprehensiveValidationTest.php:348,
tests/Factory/AuditLoggerFactoryTest.php:95, tests/ContextProcessorTest.php:289
Problem: Only prove no exception; silent acceptance of bad config still passes.
Evidence: A validator that accepts-and-mangles input passes all of these.
Impact: For validators guarding masking config, silent acceptance means unmasked data
downstream.
Fix: Assert observable outcomes, or use an explicit `assertNoException`-style helper.

#### [NP-015] Partial setUp() extraction leaves duplicated inline plugin fixtures

Category: tests
Area: tests/Builder/PluginAwareProcessorTest.php:447+, GdprProcessorBuilderTest,
GdprProcessorBuilderEdgeCasesTest
Problem: PR #115 extracted `$this->testPlugin` into setUp() but ~10 tests still define
identical inline anonymous-class plugins.
Evidence: e.g. PluginAwareProcessorTest lines 463+, 609+, 622+.
Impact: Duplicated fixtures drift independently.
Fix: Finish the extraction; parameterize only where a custom hook is needed.

#### [NP-016] Strategy layer not wired into GdprProcessor (drift source)

Category: maintainability
Area: src/Strategies/*, src/SerializedDataProcessor.php, src/Streaming/StreamingProcessor.php
Problem: Public "@api" subsystems are parallel implementations not referenced by the core
pipeline; `FieldPathMaskingStrategy` handles custom regex correctly while the wired
`ContextProcessor` does not (NP-003).
Evidence: Grep shows no references from the core pipeline.
Impact: Two masking paths drift; correct behavior exists but is unreachable.
Fix: Consolidate onto one masking path, or add cross-tests asserting identical output.

#### [NP-045] `sk_` API-key pattern alternation is shadowed and untestable

Category: tests
Area: src/DefaultPatterns.php (API key pattern), tests/GdprDefaultPatternsTest.php:130-132
Problem: the test API-key value at tests/GdprDefaultPatternsTest.php:130 (15-char
suffix) matches only the generic
`[A-Za-z0-9\-_]{20,}` alternation, never the `sk_(live|test)_[A-Za-z0-9]{16,}` branch; any
string matching the `sk_` branch is always matched by the generic branch first.
Evidence: Length analysis of the alternations; no test exercises the `sk_` branch.
Impact: Dead pattern branch that suggests coverage which does not exist.
Fix: Test both alternations separately, or remove the shadowed `sk_` branch and document.

### Low

#### [NP-017] Tautological assertInstanceOf(Throwable) in catch blocks

Category: tests
Area: tests/RegressionTests/SecurityRegressionTest.php:113,380;
ComprehensiveValidationTest.php:447
Problem: The caught value is a Throwable by definition.
Evidence: Assertion can never fail.
Impact: Masks which exception types are actually acceptable.
Fix: Assert the specific expected exception class(es); rethrow unexpected ones.
Status note: The CriticalBugRegressionTest occurrence was removed by NP-031's fix; the
remaining sites belong with the NP-006/NP-013 test restructuring.

#### [NP-019] Codecov upload failures invisible

Category: reliability
Area: .github/workflows/ci.yml:92-96
Problem: `fail_ci_if_error: false` plus secret token means missing/rotated CODECOV_TOKEN
silently skips upload forever.
Evidence: Config as written.
Impact: Coverage tracking dies silently.
Fix: `fail_ci_if_error: true` for push events, or alert on upload failure.

### Advisory

#### [NP-020] Dependency and permission hygiene (grouped)

Category: conventions
Area: composer.json:46-48,75; .github/workflows/pr-lint.yml:23-29;
.github/workflows/stale.yml:11-14; psalm.xml:62-137; phpstan.neon
Problem: (a) `illuminate/*: "*"` with `minimum-stability: dev` tests a moving target;
(b) pr-lint has `contents/actions/issues: write` for a lint job; (c) stale.yml carries
unused top-level perms and a caller-less `workflow_call`; (d) psalm.xml/phpstan.neon carry
~25/~30 blanket suppressions while CLAUDE.md declares zero-tolerance.
Evidence: Config as written.
Impact: Larger attack/drift surface; policy and config contradict each other.
Fix: Constrain illuminate majors; trim workflow perms; document the suppressions as approved
exceptions or burn them down.

#### [NP-046] Generated literal-type docblocks on test doubles add churn

Category: tests
Area: tests/ PR-wide, e.g. tests/Builder/GdprProcessorBuilderEdgeCasesTest.php:168-174,
tests/GdprProcessorComprehensiveTest.php:275-286
Problem: Dozens of `@psalm-return 'plugin-1'`-style docblocks on trivial anonymous-class
methods; several place `#[\Override]` before the docblock, detaching it in some tools.
Evidence: Diff of PR #115.
Impact: Docblocks desynchronize on the next value change and bury real diffs.
Fix: Drop literal `@psalm-return` docblocks on test doubles; rely on native return types.

## Fixed

### Pass 1 — 2026-07-03

#### [NP-008] release.yml changelog extraction could never succeed

Fixed: 2026-07-03
Notes: Extraction now tries `## [vX.Y.Z]`, falls back to the `## [Unreleased]` section, then
to "Release $TAG_NAME". Verified with actionlint and a simulation against CHANGELOG.md.

#### [NP-018] release.yml interpolated tag name directly into shell

Fixed: 2026-07-03
Notes: Tag passed via `env: TAG_NAME:` on the changelog and archive steps; scripts reference
`"$TAG_NAME"`; awk pattern built with `-v tag=` and escaped brackets.

#### [NP-021] CLAUDE.md context-mode section failed markdownlint and editorconfig

Fixed: 2026-07-03
Notes: Second H1 demoted to H2 (MD025), blank lines added around headings/lists
(MD022/MD032), long lines wrapped to 120 (MD013/ec), code-span space fixed (MD038).
Also added a "Fallback — ctx tools unavailable" subsection codifying context-window
discipline when the MCP server is not connected.

#### [NP-022] docs/plugin-development.md table pipes misaligned (MD060)

Fixed: 2026-07-03
Notes: Table repadded treating emoji as width-2; markdownlint exits 0.

#### [NP-023] Psalm INFO: redundant (string) cast in JsonMaskingTest

Fixed: 2026-07-03
Notes: Removed redundant cast at tests/JsonMaskingTest.php:380 (zero-tolerance INFO policy).

#### [NP-024] PatternValidator array_any closure typed int|false with misleading inline docblock

Fixed: 2026-07-03
Notes: Closure now `fn(string $p): bool => preg_match($p, $pattern) === 1`; inline docblock
removed. (PR #115 CodeRabbit comment, verified valid.)

#### [NP-025] Gdpr facade documented non-existent methods

Fixed: 2026-07-03
Notes: Removed `maskWithRegex()`, `removeField()`, `replaceWith()`, `clearPatternCache()`,
renamed `validatePatterns` → `validatePatternsArray`, added real methods
(`maskMessage`, `recursiveMask`, `setAuditLogger`); dropped unused FieldMaskConfig import.
(PR #115 CodeRabbit comment, verified valid.)

#### [NP-026] config/gdpr.php examples referenced non-existent GdprProcessor::removeField/replaceWith

Fixed: 2026-07-03
Notes: Examples now reference `FieldMaskConfig::remove()` / `FieldMaskConfig::replace()`.

#### [NP-027] FailureMode multi-line @psalm-return (PR review comment)

Fixed: 2026-07-03
Notes: Already single-line `non-empty-string` in current code; PR thread outdated.

#### [NP-028] StreamingProcessor generator/statistics annotations (PR review comments)

Fixed: 2026-07-03
Notes: Generator 4th generic is `void`; getStatistics returns
`array{processed: non-negative-int, ...}` in current code; PR threads outdated.

#### [NP-029] Test closure PHPDoc shapes (PR review comments)

Fixed: 2026-07-03
Notes: StreamingProcessorTest (3 sites) and CallbackMaskingStrategyTest already use correct
array-shape `@return`; PR threads outdated.

#### [NP-030] SonarQube hardcoded-credential flags in TestConstants (PR review threads)

Fixed: 2026-07-03
Notes: Values already sanitized to placeholders in commit 3bc15d7; the 5 GitHub Advanced
Security threads are marked outdated on the PR.

#### [NP-031] CriticalBugRegressionTest ReDoS loop validated the wrong variable

Fixed: 2026-07-03
Notes: Second loop now passes the delimited `$fullPattern` to `validateAll()`; runtime
probing showed all six patterns are rejected, so the test now `fail()`s if validation
passes and asserts `InvalidRegexPatternException` otherwise. Vacuous `assertTrue(true)`
removed. Verified: 18 tests / 85 assertions pass in Docker.

#### [NP-032] testSetAuditLoggerDelegates never verified delegation

Fixed: 2026-07-03
Notes: Test now adds a field path, processes a record that masks context, and asserts the
captured `$logs` is non-empty with the expected path. Verified in Docker.

#### [NP-033] 13 hardcoded constant values in five test files

Fixed: 2026-07-03
Notes: Replaced with TestConstants references (IBAN_FI_COMPACT, PHONE_INTL, DOB, PASSPORT,
CONTEXT_PHONE). `php check_for_constants.php` exits 0.

#### [NP-034] Dead placeholder constants in TestConstants

Fixed: 2026-07-03
Notes: Deleted unused `SECRET_TOKEN` and `CREDENTIAL_VALUE_ALT`. Kept `API_KEY_TEST` and
`BEARER_TOKEN` (used by tests/TestHelpers.php).

#### [NP-035] rector.php withSkip path-keyed entries were silently discarded

Fixed: 2026-07-03
Notes: Converted to plain string entries; skips are now effective. Rector dry-run still
exits 0 with no wanted changes.

#### [NP-036] rector.php contradictory type-declaration configuration

Fixed: 2026-07-03
Notes: Removed `typeDeclarations: false` + misleading comment from withPreparedSets();
`SetList::TYPE_DECLARATION` stays, matching actual behavior.

#### [NP-037] ci.yml phpcs checked fewer paths than the canonical lint script

Fixed: 2026-07-03
Notes: Inline command replaced with `composer lint:tool:phpcs` (covers examples/ and
config/ too).

#### [NP-038] composer.json hardcoded "version": "1.0.0"

Fixed: 2026-07-03
Notes: Field removed; composer.lock content-hash updated to match (computed with Composer's
own algorithm; `composer validate` exits 0). A full `composer update --lock` was not
possible: it re-resolves and hits a pre-existing, unrelated `psalm/plugin-phpunit`
constraint error — worth a follow-up.

#### [NP-039] psalm.xml dead allowPhpStormGenerics attribute

Fixed: 2026-07-03
Notes: Attribute removed; not part of Psalm 6's schema.

#### [NP-040] .gitignore ignored the tracked composer.lock

Fixed: 2026-07-03
Notes: `/composer.lock` line removed.

#### [NP-041] CONTRIBUTING.md overstated PHPStan as "max level"

Fixed: 2026-07-03
Notes: Now says "level 6", matching phpstan.neon.

#### [NP-042] RetryStrategy #[\Override] placed above the method docblock

Fixed: 2026-07-03
Notes: Attribute moved to directly precede the function declaration.

#### [NP-043] RetentionPolicy dead === false branch on DateTimeImmutable::modify()

Fixed: 2026-07-03
Notes: On PHP 8.4 `modify()` never returns false for this fixed format; guard and unused
exception import removed; stray double blank line after namespace cleaned.

## Invalid

### Pass 1 — 2026-07-03

#### [NP-044] TestConstants::MASK_REDACTED_BRACKETS aliases the production constant

Notes: The reviewer proposed pinning an independent `'[REDACTED]'` literal so tests detect
production-mask changes. Rejected: the project's `check_for_constants.php` policy mandates
referencing MaskConstants values instead of literals in tests, and would flag the literal.
Policy wins; the alias stays.
