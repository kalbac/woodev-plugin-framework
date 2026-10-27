# Gotcha: [testing/i18n] — `WP_Locale_Switcher` caches the available languages in `init()`

> Tags: integration, switch_to_locale, i18n | Session: s200

**What happens:** an integration test adds a `get_available_languages` filter (to make `ru_RU` "installed") and then calls
`switch_to_locale( 'ru_RU' )`. It returns `false` and the test fails with `Failed asserting that false is true` — in CI, on
every WordPress version — while the code under test is correct.

**Why:** the global `$wp_locale_switcher` copies `get_available_languages()` into its own property once, in `init()`, during
bootstrap. A filter added later never reaches it; `switch_to_locale()` refuses a locale it believes is not installed.

❌ **Wrong:**

```php
add_filter( 'get_available_languages', static fn( array $l ): array => array_merge( $l, [ 'ru_RU' ] ) );
$this->assertTrue( switch_to_locale( 'ru_RU' ) ); // false: the switcher cached the list at bootstrap
```

✅ **Correct:** build a fresh switcher after the filter and restore the global afterwards.

```php
global $wp_locale_switcher;
$original           = $wp_locale_switcher;
$wp_locale_switcher = new \WP_Locale_Switcher();
$wp_locale_switcher->init();
try {
	$this->assertTrue( switch_to_locale( 'ru_RU' ) );
} finally {
	restore_current_locale();
	remove_filter( 'locale', [ $wp_locale_switcher, 'filter_locale' ] );
	remove_filter( 'determine_locale', [ $wp_locale_switcher, 'filter_locale' ] );
	$wp_locale_switcher = $original;
}
```

Seen in PR #1494 (`tests/integration/FrameworkTranslationsTest.php`); the neighbouring test already used the fresh-switcher pattern.

## Related

- [an-external-language-pack-needs-an-explicit-bundled-fallback](an-external-language-pack-needs-an-explicit-bundled-fallback.md) — the behaviour that test covers
- [../gotcha-index/testing.md](../gotcha-index/testing.md) — testing index
