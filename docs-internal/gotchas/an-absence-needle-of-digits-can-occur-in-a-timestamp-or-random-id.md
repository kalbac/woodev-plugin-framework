# A digit-only "must not contain" needle is clock-flaky when the JSON carries `time()` or a random hex id

**Namespace:** `[testing/unit]` · **Discovered:** s151 (04.10.2026) · **Measured on:** macOS, PHP 8.5

## What happens

`EventBuilderTest::test_an_uncaught_fatal_is_attributed_through_its_stack_text_and_keeps_only_the_exception_class`
(merged with #130) asserted that `'999'` — part of a customer phone in the exception text — is absent
from the whole encoded event. CI was green; a local run on `main` failed at timestamp `1791088999`.
The same needle can also land in the random 32-hex `event_id` (digits are 10/16 of its alphabet).

## Root cause

The needle proves "this customer text was stripped" only if nothing ELSE in the haystack can produce it.
An event envelope always carries `time()` and a random id, so a short digit run appears by chance —
roughly once per few thousand seconds for `999` in the timestamp alone.

## Fix

❌ `foreach ( [ 'Иван', '999' ] as $needle ) { $this->assertStringNotContainsString( $needle, $json ); }`

✅ Pick a needle only the stripped text can produce (`'123-45-67'`, with its hyphens), or remove
`timestamp` and `event_id` from the encoded event before asserting — the two sibling tests in the same
directory already do the latter.

## Related

- [brain-monkey-function-pollution](brain-monkey-function-pollution.md) — the other "green on CI, red locally" cause in the same suite this session
- [../specs/2026-10-04-error-reporter-design.md](../specs/2026-10-04-error-reporter-design.md) — D5, what the test protects
