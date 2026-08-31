# Gotcha: [testing/*] — Tests that assert on framework source AS TEXT break on any mechanical reformat, and a private-method swap breaks tests that never name it
> Tags: testing, phpcbf, brain-monkey, refactor | Session: s110

## What happens

`phpcbf` converts 633 `array()` literals to `[]` — a semantically identical rewrite that cannot
change behaviour. The unit suite goes from green to **47 failures**. Two unrelated causes, both
invisible to any grep you would think to run first.

## Root cause

**1. Some tests read framework source with `file_get_contents()` and assert on its TEXT.** They pin
wiring that has no runtime seam — "this hook really is registered on that action":

```php
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/handlers/class-cron-handler.php' );
$this->assertStringContainsString(
    "add_action( 'woodev_weekly_scheduled_events', array( \$this, 'prune_license_command_nonces' ) )",
    $source
);
```

Any reformatting of that line — array syntax, alignment, wrapping — breaks the assertion while the
behaviour is untouched. The test is doing its job; it just also pins the formatting.

**2. Swapping a plain PHP function for a WP wrapper breaks tests that never mention the method.**
`parse_url()` -> `wp_parse_url()` inside four **private static** methods of `Woodev_API_Base`
produced 45 errors in `ApiBaseChallengeRedirectTest`. Brain Monkey defines no WordPress functions,
so the wrapper is simply undefined at runtime. The methods are private and the test exercises them
through a public entry point, so `grep -rln 'is_same_origin_uri' tests/` finds **nothing** — the
pre-flight check that looks safest is the one that misses this.

## Fix

✅ For the source-text assertions — update the expected string to the new source, do not weaken the
assertion into a looser pattern. It is pinning something real:

```php
"add_action( 'woodev_weekly_scheduled_events', [ \$this, 'prune_license_command_nonces' ] )"
```

✅ For the WP wrapper — add the Brain Monkey stub the other suites already define, preserving the
`$component` default of `-1`:

```php
Functions\when( 'wp_parse_url' )->alias( static function ( $url, $component = -1 ) {
    return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
} );
```

❌ Do not conclude a wrapper swap is test-safe because `grep` found no test naming the method.
Private methods are reached through public ones. **The only reliable pre-flight is running the full
suite** — which is also the standing rule after a subagent wires a new call into a shared path.

## Related

- [a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli](a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli.md) — the same s110 change; that half was about measuring, this half about the blast radius
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the same class of failure from EOL rather than syntax: a source-asserting test broken by a rewrite that `git diff` hides
