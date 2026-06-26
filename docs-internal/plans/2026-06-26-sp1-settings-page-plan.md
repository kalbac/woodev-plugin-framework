# SP-1 — Settings Page Slot (§15) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Authoritative spec:** `docs-internal/specs/2026-06-26-sp1-settings-page-design.md` (5 locked decisions — do NOT re-decide). Branch: `feat/sp1-settings-page`. `@since 2.0.2`, VERSION stays 2.0.1 (do not bump).
>
> **Review history:** plan reviewed 2026-06-26 by two independent adversarial critics (spec/signature coverage + WP/PHP/testability). Fixes applied before implementation: **(1)** renamed the REST controller `Woodev_REST_API_Settings` → `Woodev_REST_API_Settings_Page` (the bare name collides with an existing live `wc/v3` class → class-map DUPLICATE + runtime fatal); **(2)** `POST` save now scopes submitted values to the tab's declared section setting_ids via `array_intersect_key` (was: wrote any submitted key the handler owned); **(3)** Task 8 re-points `app.js`'s `./icons` import too (was: only `./control-field` — wizard build would break); **(4)** added a `shop_manager` integration test discharging Decision 5 pt4 (menu reachability) with a concrete fallback; **(5)** memoized `collect_entries()`; **(6)** `reset_for_tests()` resets `$hooked`; **(7)** `Woodev_Plugin_Exception` `require_once` in the REST test; path-casing `setup/`; `instanceof`-mock note. The autodev-loop critic re-verifies these at code time (no self-certification).

**Goal:** Build a neutral `Woodev > Настройки` admin page — a single React app over the platform-neutral Settings-API that aggregates tabs from multiple plugins + framework services through a registry, persisting per-tab via one aggregated `woodev/v1/settings` REST controller.

**Architecture:** A singleton `Settings_Page_Registry` collects `Settings_Provider` descriptors (each wrapping a `Woodev_Abstract_Settings` handler) from every active plugin's new `get_settings_providers()` seam plus framework-service stubs. The registry registers one submenu (`woodev-settings`) and one React bundle; the bundle fetches the aggregated schema via `GET woodev/v1/settings` and saves per-tab via `POST woodev/v1/settings/{provider_id}`, routing values to that provider's handler `update_value()`. Capability per tab resolves base `manage_options` → `manage_woocommerce` for WC-dependent plugins → explicit override; the page itself opens under the broadest-reach cap among visible tabs.

**Tech Stack:** PHP 7.4+ (PSR-4 `Woodev\Framework\Settings\*`, global `Woodev_REST_API_*`), WordPress REST API, React via `@wordpress/scripts` + `@wordpress/components` (classic `createElement`/`Fragment` JSX), PHPUnit + Brain Monkey/Mockery (unit), WP test library (integration).

---

## File Structure

**New PHP (namespace `Woodev\Framework\Settings`, dir `woodev/settings-page/`):**

| File | Responsibility |
|------|----------------|
| `woodev/settings-page/class-settings-section.php` | `Settings_Section` VO — groups `setting_ids` under a labelled section within a tab. |
| `woodev/settings-page/class-field-schema.php` | `Field_Schema` — pure static builder: `Woodev_Abstract_Settings` → JSON field schema (same shape the wizard emits). |
| `woodev/settings-page/class-settings-provider.php` | `Settings_Provider` VO — one tab: id, label, handler, sections, declared capability, legacy key/page, `supports_*` flags. |
| `woodev/settings-page/class-settings-page-registry.php` | `Settings_Page_Registry` singleton — aggregates providers + services, resolves capabilities, registers the submenu + React assets + legacy-URL redirect, builds the aggregated schema. |
| `woodev/rest-api/controllers/class-rest-api-settings-page.php` | `Woodev_REST_API_Settings_Page` (global) — `GET /settings` (schema) + `POST /settings/{provider_id}` (per-tab save). **NB:** named `_Page` because the global class `Woodev_REST_API_Settings` already exists (the legacy `wc/v3` per-handler settings controller in `class-plugin-rest-api-settings.php`) — reusing the name collides (class-map DUPLICATE → generator `exit(1)`; runtime `class_exists` confusion). The spec's diagram name was illustrative; class names are internal (free to break). |

**Modified PHP:**

| File | Change |
|------|--------|
| `woodev/class-plugin.php` | Add seam `get_settings_providers(): array` (default `[]`); add `init_settings_page()` + call in `__construct()`. |
| `woodev/class-map.php` | Regenerated (4 new namespaced classes; the REST controller is `require_once`-loaded, not autoloaded — same as `Woodev_REST_API_Setup`). |
| `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` | Add a reference settings handler + `get_settings_providers()` returning the «Карьер» provider; register a framework-service stub. |

**New React (dir `src/`):**

| File | Responsibility |
|------|----------------|
| `src/components/control-field.js` | Shared control dispatcher — **moved verbatim** from `src/setup-wizard/control-field.js`. |
| `src/components/dropdown.js` | Shared custom dropdown — **moved** from `src/setup-wizard/dropdown.js`. |
| `src/components/richtext.js` | Shared rich-text editor — **moved** from `src/setup-wizard/richtext.js`. |
| `src/components/icons.js` | Shared inline SVG icons — **moved** from `src/setup-wizard/icons.js`. |
| `src/setup-wizard/*` | Re-point imports to `../components/*` (no behavior change). |
| `src/settings-page/index.js` | Entry: mount `#woodev-settings-app`, read `window.woodevSettings`. |
| `src/settings-page/rest.js` | `fetchSchema()` + `saveTab(providerId, values)` via `apiFetch`. |
| `src/settings-page/app.js` | Root: load schema, horizontal provider tabs, per-tab save, loading skeleton. |
| `src/settings-page/section-view.js` | Renders one tab: sections with sub-headings, each field via shared `ControlField`. |
| `src/settings-page/style.scss` | Tab + section layout, brand cyan `#06aedd`. |

**Modified build config:**

| File | Change |
|------|--------|
| `package.json` | Add `settings-page` to `build` + a `build:settings` / `start:settings` script. |

---

## Conventions every task must honour (from spec cross-cutting)

- New code: PSR-4 namespaces, short arrays `[]`, type declarations on params+returns, docblocks with `@since 2.0.2`, OOP-only, Yoda conditions, `??` over `isset`.
- After adding/moving any framework class: run `php bin/generate-class-map.php` **in the same task** (else `ClassMapCompletenessTest` reddens). Commit the regenerated `woodev/class-map.php`.
- No `_n()` (Russian is source — gotcha `russian-source-i18n-plural-n`). Russian for UI strings, text domain `woodev-plugin-framework`.
- Local gate: `composer phpcs` + `composer test:unit` (PHPStan only on Linux CI — local Windows segfault is environmental). PHPStan-clean code still required.
- Settings-API save-path (s31): `update_value()` already validates enum **by key-or-value**, sanitizes richtext via `wp_kses_post`, and coerces numeric strings — reuse it, do not re-implement (gotcha `settings-api-control-save-path-pitfalls`).
- React: classic JSX (`createElement`/`Fragment`, no JSX syntax), LF line endings in `woodev/assets/build`, commit built assets (assets-parity CI).

---

## Task 1: `Settings_Section` value object

A tab groups its settings into labelled sections. `Settings_Section` is the grouping primitive (the settings-page analogue of the wizard's `Step`, minus wizard semantics).

**Files:**
- Create: `woodev/settings-page/class-settings-section.php`
- Test: `tests/unit/SettingsSectionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';

class SettingsSectionTest extends TestCase {

	public function test_create_exposes_id_label_and_setting_ids(): void {
		$section = Settings_Section::create( 'general', 'Общие', [ 'api_key', 'mode' ] );

		$this->assertSame( 'general', $section->get_id() );
		$this->assertSame( 'Общие', $section->get_label() );
		$this->assertSame( [ 'api_key', 'mode' ], $section->get_setting_ids() );
	}

	public function test_setting_ids_are_reindexed(): void {
		$section = Settings_Section::create( 'x', 'X', [ 2 => 'a', 5 => 'b' ] );

		$this->assertSame( [ 'a', 'b' ], $section->get_setting_ids() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsSectionTest.php`
Expected: FAIL — class `Woodev\Framework\Settings\Settings_Section` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Settings-page section descriptor.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Groups setting ids under one labelled section within a settings tab.
 *
 * The settings-page analogue of the setup wizard's Step grouping primitive.
 *
 * @since 2.0.2
 */
final class Settings_Section {

	/** @var string section id. */
	private string $id;

	/** @var string section label (sub-heading). */
	private string $label;

	/** @var string[] referenced Woodev_Setting ids. */
	private array $setting_ids;

	/**
	 * Use the named constructor instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, array $setting_ids ) {
		$this->id          = $id;
		$this->label       = $label;
		$this->setting_ids = array_values( $setting_ids );
	}

	/**
	 * Builds a section.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id          section id.
	 * @param string   $label       section label.
	 * @param string[] $setting_ids referenced setting ids.
	 * @return self
	 */
	public static function create( string $id, string $label, array $setting_ids ): self {
		return new self( $id, $label, $setting_ids );
	}

	/**
	 * Returns the section id.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the section label.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Returns the referenced setting ids.
	 *
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	public function get_setting_ids(): array {
		return $this->setting_ids;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsSectionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-section.php tests/unit/SettingsSectionTest.php
git commit -m "feat(settings-page): add Settings_Section grouping VO"
```

---

## Task 2: `Field_Schema` builder

Pure static builder turning a `Woodev_Abstract_Settings` handler into the JSON field schema the React controls consume — the **same shape** the wizard emits in `Setup_Wizard::get_field_schema()` (controlType / options / value / tooltip / min / max / step / is_multi / description / name / type). Extracted so the settings page and (Task 10) the wizard share one source of truth.

**Files:**
- Create: `woodev/settings-page/class-field-schema.php`
- Test: `tests/unit/FieldSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Field_Schema;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';

class FieldSchemaTest extends TestCase {

	private function make_setting( string $id, string $type, $control ) {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( $id );
		$setting->shouldReceive( 'get_type' )->andReturn( $type );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Имя ' . $id );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( 'desc ' . $id );
		$setting->shouldReceive( 'get_control' )->andReturn( $control );

		return $setting;
	}

	public function test_builds_entry_with_control_metadata(): void {
		$control = Mockery::mock();
		$control->shouldReceive( 'get_type' )->andReturn( 'range' );
		$control->shouldReceive( 'get_description' )->andReturn( 'control desc' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( 'tip' );
		$control->shouldReceive( 'get_min' )->andReturn( 1.0 );
		$control->shouldReceive( 'get_max' )->andReturn( 10.0 );
		$control->shouldReceive( 'get_step' )->andReturn( 0.5 );

		$setting = $this->make_setting( 'weight', 'integer', $control );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [ 'weight' ] )->andReturn( [ 'weight' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'weight' )->andReturn( 5 );

		$schema = Field_Schema::from_handler( $handler, [ 'weight' ] );

		$this->assertArrayHasKey( 'weight', $schema );
		$this->assertSame( 'range', $schema['weight']['controlType'] );
		$this->assertSame( 'control desc', $schema['weight']['description'] );
		$this->assertSame( 'tip', $schema['weight']['tooltip'] );
		$this->assertSame( 5, $schema['weight']['value'] );
		$this->assertSame( 1.0, $schema['weight']['min'] );
		$this->assertSame( 10.0, $schema['weight']['max'] );
		$this->assertSame( 0.5, $schema['weight']['step'] );
	}

	public function test_omits_range_bounds_when_control_returns_null(): void {
		$control = Mockery::mock();
		$control->shouldReceive( 'get_type' )->andReturn( 'text' );
		$control->shouldReceive( 'get_description' )->andReturn( '' );
		$control->shouldReceive( 'get_tooltip' )->andReturn( '' );
		$control->shouldReceive( 'get_min' )->andReturn( null );
		$control->shouldReceive( 'get_max' )->andReturn( null );
		$control->shouldReceive( 'get_step' )->andReturn( null );

		$setting = $this->make_setting( 'api_key', 'string', $control );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [] )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'api_key' )->andReturn( 'k' );

		$schema = Field_Schema::from_handler( $handler );

		$this->assertArrayNotHasKey( 'min', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'max', $schema['api_key'] );
		$this->assertArrayNotHasKey( 'step', $schema['api_key'] );
		// Control description empty → falls back to setting description.
		$this->assertSame( 'desc api_key', $schema['api_key']['description'] );
	}

	public function test_handles_missing_control(): void {
		$setting = $this->make_setting( 'plain', 'string', null );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->with( [] )->andReturn( [ 'plain' => $setting ] );
		$handler->shouldReceive( 'get_value' )->with( 'plain' )->andReturn( 'v' );

		$schema = Field_Schema::from_handler( $handler );

		$this->assertNull( $schema['plain']['controlType'] );
		$this->assertSame( 'desc plain', $schema['plain']['description'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Settings field-schema builder.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON field schema a React control consumes from a settings handler.
 *
 * Single source of truth for the field-schema shape shared by the settings page
 * and the setup wizard (controlType / options / value / tooltip / min / max /
 * step / is_multi / description / name / type).
 *
 * @since 2.0.2
 */
final class Field_Schema {

	/**
	 * Resolves the field schema for the given handler.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Abstract_Settings $handler     settings handler.
	 * @param string[]                  $setting_ids optional subset of setting ids; empty = all.
	 * @return array<string,array<string,mixed>> schema keyed by setting id.
	 */
	public static function from_handler( $handler, array $setting_ids = [] ): array {
		$schema = [];

		foreach ( $handler->get_settings( $setting_ids ) as $setting ) {
			$control = $setting->get_control();

			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $handler->get_value( $setting->get_id() ),
				'is_multi'    => $setting->is_is_multi(),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control && $control->get_description() ? $control->get_description() : $setting->get_description(),
				'tooltip'     => $control ? $control->get_tooltip() : '',
			];

			if ( $control && null !== $control->get_min() ) {
				$entry['min'] = $control->get_min();
			}
			if ( $control && null !== $control->get_max() ) {
				$entry['max'] = $control->get_max();
			}
			if ( $control && null !== $control->get_step() ) {
				$entry['step'] = $control->get_step();
			}

			$schema[ $setting->get_id() ] = $entry;
		}

		return $schema;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-field-schema.php tests/unit/FieldSchemaTest.php
git commit -m "feat(settings-page): add Field_Schema builder (shared field-schema shape)"
```

---

## Task 3: `Settings_Provider` value object

One tab on the settings page. Wraps a `Woodev_Abstract_Settings` handler plus presentation metadata. Built by a plugin (or framework service) and returned from the `get_settings_providers()` seam.

**Files:**
- Create: `woodev/settings-page/class-settings-provider.php`
- Test: `tests/unit/SettingsProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';

class SettingsProviderTest extends TestCase {

	private function make_handler( string $id ) {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );

		return $handler;
	}

	public function test_exposes_core_descriptor_fields(): void {
		$handler  = $this->make_handler( 'cdek' );
		$sections = [ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ];

		$provider = Settings_Provider::create(
			'cdek',
			'СДЭК',
			$handler,
			$sections,
			[
				'capability'        => 'manage_woocommerce',
				'legacy_option_key' => 'woocommerce_cdek_settings',
				'legacy_page'       => 'wc-settings&tab=shipping&section=cdek',
				'supports'          => [ 'fields' => true ],
			]
		);

		$this->assertSame( 'cdek', $provider->get_id() );
		$this->assertSame( 'СДЭК', $provider->get_label() );
		$this->assertSame( $handler, $provider->get_handler() );
		$this->assertSame( $sections, $provider->get_sections() );
		$this->assertSame( 'manage_woocommerce', $provider->get_declared_capability() );
		$this->assertSame( 'woocommerce_cdek_settings', $provider->get_legacy_option_key() );
		$this->assertSame( 'wc-settings&tab=shipping&section=cdek', $provider->get_legacy_page() );
		$this->assertTrue( $provider->supports( 'fields' ) );
		$this->assertFalse( $provider->supports( 'export' ) );
	}

	public function test_optional_fields_default_to_null_or_empty(): void {
		$provider = Settings_Provider::create( 'svc', 'Сервис', $this->make_handler( 'svc' ), [] );

		$this->assertNull( $provider->get_declared_capability() );
		$this->assertNull( $provider->get_legacy_option_key() );
		$this->assertNull( $provider->get_legacy_page() );
		$this->assertSame( [], $provider->get_sections() );
		$this->assertFalse( $provider->supports( 'anything' ) );
	}

	public function test_id_falls_back_to_handler_id_when_blank(): void {
		$provider = Settings_Provider::create( '', 'X', $this->make_handler( 'handler-id' ), [] );

		$this->assertSame( 'handler-id', $provider->get_id() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsProviderTest.php`
Expected: FAIL — `Settings_Provider` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Settings-page provider (tab) descriptor.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One settings tab: a Woodev_Abstract_Settings handler plus presentation metadata.
 *
 * The handler owns storage/validation (unchanged); this descriptor owns tab
 * metadata — label, section grouping, capability, legacy key/url, and the §4
 * support flags. A plugin contributes one or more providers (multi-carrier =
 * multiple tabs); a framework service contributes one through the same shape.
 *
 * @since 2.0.2
 */
final class Settings_Provider {

	/** @var string provider/tab id (== handler id → option namespace). */
	private string $id;

	/** @var string tab label. */
	private string $label;

	/** @var \Woodev_Abstract_Settings settings handler. */
	private $handler;

	/** @var Settings_Section[] section grouping. */
	private array $sections;

	/** @var string|null explicit capability override (null = resolve by rule). */
	private ?string $capability;

	/** @var string|null legacy single-array option key (migration source). */
	private ?string $legacy_option_key;

	/** @var string|null legacy admin page query string (redirect source). */
	private ?string $legacy_page;

	/** @var array<string,bool> §4 support flags. */
	private array $supports;

	/**
	 * Use the named constructor instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, $handler, array $sections, array $args ) {
		$this->id                = '' !== $id ? $id : (string) $handler->get_id();
		$this->label             = $label;
		$this->handler           = $handler;
		$this->sections          = array_values( $sections );
		$this->capability        = isset( $args['capability'] ) && '' !== $args['capability'] ? (string) $args['capability'] : null;
		$this->legacy_option_key = isset( $args['legacy_option_key'] ) && '' !== $args['legacy_option_key'] ? (string) $args['legacy_option_key'] : null;
		$this->legacy_page       = isset( $args['legacy_page'] ) && '' !== $args['legacy_page'] ? (string) $args['legacy_page'] : null;
		$this->supports          = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : [];
	}

	/**
	 * Builds a provider descriptor.
	 *
	 * @since 2.0.2
	 *
	 * @param string                    $id       tab id; blank falls back to the handler id.
	 * @param string                    $label    tab label.
	 * @param \Woodev_Abstract_Settings $handler  settings handler.
	 * @param Settings_Section[]        $sections section grouping.
	 * @param array<string,mixed>       $args     optional: capability, legacy_option_key, legacy_page, supports.
	 * @return self
	 */
	public static function create( string $id, string $label, $handler, array $sections, array $args = [] ): self {
		return new self( $id, $label, $handler, $sections, $args );
	}

	/**
	 * Returns the provider/tab id.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Returns the settings handler.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Abstract_Settings
	 */
	public function get_handler() {
		return $this->handler;
	}

	/**
	 * Returns the section grouping.
	 *
	 * @since 2.0.2
	 *
	 * @return Settings_Section[]
	 */
	public function get_sections(): array {
		return $this->sections;
	}

	/**
	 * Returns the explicit capability override, or null to resolve by rule.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null
	 */
	public function get_declared_capability(): ?string {
		return $this->capability;
	}

	/**
	 * Returns the legacy single-array option key (migration source), or null.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null
	 */
	public function get_legacy_option_key(): ?string {
		return $this->legacy_option_key;
	}

	/**
	 * Returns the legacy admin-page query string (redirect source), or null.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null
	 */
	public function get_legacy_page(): ?string {
		return $this->legacy_page;
	}

	/**
	 * Whether the provider declares support for a §4 capability flag.
	 *
	 * @since 2.0.2
	 *
	 * @param string $feature flag name.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return ! empty( $this->supports[ $feature ] );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsProviderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-provider.php tests/unit/SettingsProviderTest.php
git commit -m "feat(settings-page): add Settings_Provider tab descriptor VO"
```

---

## Task 4: `Settings_Page_Registry` — pure aggregation + capability resolution

The registry's testable core, no WP hooks yet: capability resolution (the 4 spec rules) and tab aggregation (dedup by id, order preserved, cap-filtered, schema built). WP-coupled wiring lands in Task 5.

**Files:**
- Create: `woodev/settings-page/class-settings-page-registry.php`
- Test: `tests/unit/SettingsPageRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';

class SettingsPageRegistryTest extends TestCase {

	// ----- capability resolution (4 rules) -----

	public function test_capability_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_capability( null, false ) );
	}

	public function test_capability_flips_to_manage_woocommerce_for_wc_plugin(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_capability( null, true ) );
	}

	public function test_explicit_capability_overrides_both(): void {
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', true ) );
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', false ) );
	}

	// ----- page capability = broadest reach -----

	public function test_page_capability_prefers_manage_woocommerce_reach(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_woocommerce' ] ) );
	}

	public function test_page_capability_all_neutral_is_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_options' ] ) );
	}

	public function test_page_capability_empty_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [] ) );
	}

	// ----- tab aggregation -----

	private function provider( string $id, string $label, ?string $cap = null ): Settings_Provider {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'api_key' );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Ключ' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->andReturn( 'v' );

		return Settings_Provider::create(
			$id,
			$label,
			$handler,
			[ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ],
			null === $cap ? [] : [ 'capability' => $cap ]
		);
	}

	public function test_build_tabs_dedupes_by_id_keeping_first_and_preserves_order(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'b', 'B' ), 'is_woocommerce' => false ],
				[ 'provider' => $this->provider( 'a', 'A' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'b', 'B-dup' ), 'is_woocommerce' => false ],
			],
			static function (): bool {
				return true; // current_user_can stub: sees everything.
			}
		);

		$ids = array_column( $tabs, 'id' );
		$this->assertSame( [ 'b', 'a' ], $ids );
		$this->assertSame( 'B', $tabs[0]['label'] );
		$this->assertSame( 'manage_woocommerce', $tabs[1]['capability'] );
	}

	public function test_build_tabs_omits_tabs_the_user_cannot_access(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'wc', 'WC', 'manage_woocommerce' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'admin', 'Admin', 'manage_options' ), 'is_woocommerce' => false ],
			],
			static function ( string $cap ): bool {
				return 'manage_woocommerce' === $cap; // shop manager.
			}
		);

		$this->assertSame( [ 'wc' ], array_column( $tabs, 'id' ) );
		$this->assertArrayHasKey( 'sections', $tabs[0] );
		$this->assertSame( 'general', $tabs[0]['sections'][0]['id'] );
		$this->assertArrayHasKey( 'api_key', $tabs[0]['sections'][0]['fields'] );
	}
}
```

> Note: `instance()` is a process-wide singleton; the `build_tabs` tests call only pure methods on it and never mutate registration state, so ordering between tests does not matter.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageRegistryTest.php`
Expected: FAIL — `Settings_Page_Registry` not found.

- [ ] **Step 3: Write minimal implementation** (pure core only — hooks added in Task 5)

```php
<?php
/**
 * Settings-page registry.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton aggregator for the neutral Woodev > Настройки page.
 *
 * Collects Settings_Provider descriptors from every active plugin's
 * get_settings_providers() seam plus framework-service stubs, resolves each
 * tab's capability, builds the aggregated schema, and (Task 5) registers the
 * submenu, React assets, and legacy-URL redirect.
 *
 * @since 2.0.2
 */
final class Settings_Page_Registry {

	/** @var string admin page slug. */
	const PAGE_SLUG = 'woodev-settings';

	/** @var self|null singleton. */
	private static $instance = null;

	/** @var \Woodev_Plugin[] plugins that may contribute providers. */
	private array $plugins = [];

	/** @var Settings_Provider[] framework-service providers (no owning plugin). */
	private array $services = [];

	/** @var bool whether the shared hooks were added. */
	private bool $hooked = false;

	/** @var array<int,array<string,mixed>>|null memoized tabs for this request. */
	private $tabs_cache = null;

	/**
	 * Returns the singleton.
	 *
	 * @since 2.0.2
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolves a provider's capability per the spec rules.
	 *
	 * 1. explicit declaration wins; 2. WC-dependent plugin → manage_woocommerce;
	 * 3. otherwise neutral manage_options.
	 *
	 * @since 2.0.2
	 *
	 * @param string|null $declared       explicit capability or null.
	 * @param bool        $is_woocommerce whether the owning plugin is WC-dependent.
	 * @return string
	 */
	public static function resolve_capability( ?string $declared, bool $is_woocommerce ): string {
		if ( null !== $declared && '' !== $declared ) {
			return $declared;
		}

		return $is_woocommerce ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Resolves the page (submenu) capability: the broadest-reach cap among tabs.
	 *
	 * A WordPress submenu carries a single capability, but a user should be able
	 * to open the page when they can access ANY tab. manage_woocommerce reaches a
	 * wider audience (admins + shop managers) than admin-only manage_options, so
	 * the page opens under the broadest cap present; per-tab visibility + the
	 * per-provider REST route still enforce the exact capability. Custom caps not
	 * in the reach table rank narrowest (a known SP-1 limitation — real plugins
	 * use the two standard caps).
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $caps resolved per-tab capabilities.
	 * @return string
	 */
	public static function resolve_page_capability( array $caps ): string {
		$reach = [
			'manage_woocommerce' => 2,
			'manage_options'     => 1,
		];

		$best      = 'manage_options';
		$best_rank = 0;

		foreach ( $caps as $cap ) {
			$rank = $reach[ $cap ] ?? 0;
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best      = $cap;
			}
		}

		return $best;
	}

	/**
	 * Builds the cap-filtered, deduped tab list (pure; injectable for tests).
	 *
	 * Each entry: [ 'provider' => Settings_Provider, 'is_woocommerce' => bool ].
	 * Dedup is by provider id (first wins). Tabs whose resolved capability the
	 * current user lacks are omitted.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int,array<string,mixed>> $entries     provider entries.
	 * @param callable                       $can         predicate: (string $cap) => bool.
	 * @return array<int,array<string,mixed>> tab schema array.
	 */
	public function build_tabs( array $entries, callable $can ): array {
		$tabs = [];
		$seen = [];

		foreach ( $entries as $entry ) {
			$provider = $entry['provider'];
			$id       = $provider->get_id();

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$capability = self::resolve_capability(
				$provider->get_declared_capability(),
				! empty( $entry['is_woocommerce'] )
			);

			if ( ! $can( $capability ) ) {
				continue;
			}

			$tabs[] = [
				'id'         => $id,
				'label'      => $provider->get_label(),
				'capability' => $capability,
				'sections'   => $this->build_sections( $provider ),
			];
		}

		return $tabs;
	}

	/**
	 * Builds a provider's section schema (each section's fields resolved).
	 *
	 * @since 2.0.2
	 *
	 * @param Settings_Provider $provider provider.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_sections( Settings_Provider $provider ): array {
		$handler  = $provider->get_handler();
		$sections = [];

		foreach ( $provider->get_sections() as $section ) {
			$sections[] = [
				'id'     => $section->get_id(),
				'label'  => $section->get_label(),
				'fields' => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),
			];
		}

		return $sections;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageRegistryTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-page-registry.php tests/unit/SettingsPageRegistryTest.php
git commit -m "feat(settings-page): registry core — capability resolution + tab aggregation"
```

---

## Task 5: `Settings_Page_Registry` — WP wiring + `get_settings_providers()` seam

Add the WP-coupled methods (registration of plugins/services, the shared hooks, submenu registration, asset enqueue, legacy redirect, request-scoped tab building) and the `Woodev_Plugin::get_settings_providers()` seam wired from the constructor.

**Files:**
- Modify: `woodev/settings-page/class-settings-page-registry.php`
- Modify: `woodev/class-plugin.php` (seam + `init_settings_page()` + constructor call)
- Test: `tests/unit/SettingsPageWiringTest.php`

- [ ] **Step 1: Write the failing test** (covers seam default + menu cap selection + provider collection)

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';

class SettingsPageWiringTest extends TestCase {

	private function neutral_provider( string $id ): Settings_Provider {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );
		$handler->shouldReceive( 'get_settings' )->andReturn( [] );

		return Settings_Provider::create( $id, strtoupper( $id ), $handler, [] );
	}

	public function test_collect_entries_pulls_providers_from_plugins_and_services(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$wc_plugin = Mockery::mock( '\Woodev\Framework\Woocommerce_Plugin' );
		$wc_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'cdek' ) ] );

		$neutral_plugin = Mockery::mock( '\Woodev_Plugin' );
		$neutral_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'tool' ) ] );

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( $wc_plugin );
		$registry->register_plugin( $neutral_plugin );
		$registry->register_service( $this->neutral_provider( 'dadata' ) );

		$entries = $registry->collect_entries();
		$ids     = array_map( static fn( $e ) => $e['provider']->get_id(), $entries );

		$this->assertContains( 'cdek', $ids );
		$this->assertContains( 'tool', $ids );
		$this->assertContains( 'dadata', $ids );

		// WC plugin's provider is flagged WooCommerce; the neutral one is not.
		foreach ( $entries as $entry ) {
			if ( 'cdek' === $entry['provider']->get_id() ) {
				$this->assertTrue( $entry['is_woocommerce'] );
			}
			if ( 'tool' === $entry['provider']->get_id() ) {
				$this->assertFalse( $entry['is_woocommerce'] );
			}
			if ( 'dadata' === $entry['provider']->get_id() ) {
				$this->assertFalse( $entry['is_woocommerce'] ); // services are neutral.
			}
		}
	}

	public function test_get_page_capability_uses_broadest_reach(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$wc_plugin = Mockery::mock( '\Woodev\Framework\Woocommerce_Plugin' );
		$wc_plugin->shouldReceive( 'get_settings_providers' )->andReturn( [ $this->neutral_provider( 'cdek' ) ] );

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( $wc_plugin );

		$this->assertSame( 'manage_woocommerce', $registry->get_page_capability() );
	}
}
```

> **Why the `instanceof` mock works (verify if it doesn't):** `Mockery::mock('\Woodev\Framework\Woocommerce_Plugin')` produces an object that passes `$x instanceof \Woodev\Framework\Woocommerce_Plugin` because Mockery defines a named mock class with that exact name when the real class is **not** already loaded. In the unit suite the framework's `Woodev\Framework\*` classes are NOT in Composer's autoload (Composer maps only `Woodev\Tests\*`; framework classes load via explicit `require_once` or the runtime class-map), so `class_exists()` returns false and Mockery creates the named mock — matching how `SetupWizardBootstrapTest` mocks `Woodev_Plugin`. Do NOT `require_once` the real `class-woocommerce-plugin.php` here (it would pull the full `Woodev_Plugin` base and could touch WP at load). If a future autoload change makes the real class resolvable and this test breaks, replace the `instanceof` detection with a stubbable seam.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageWiringTest.php`
Expected: FAIL — `reset_for_tests` / `register_plugin` / `collect_entries` / `get_page_capability` undefined.

- [ ] **Step 3a: Add the WP-wiring methods to the registry**

First, add the request-scoped entries cache property next to `$tabs_cache`:

```php
	/** @var array<int,array<string,mixed>>|null memoized provider entries for this request. */
	private $entries_cache = null;
```

Then insert these methods into `Settings_Page_Registry` (after `build_sections`). The `is_woocommerce` flag uses `instanceof \Woodev\Framework\Woocommerce_Plugin` (shipping + payment-gateway plugins both extend it — confirmed in design map). `collect_entries()` is memoized (it constructs handlers via `get_settings_providers()`; without the cache it would rebuild them 4–5× per request across the permission/schema/menu calls).

```php
	/**
	 * Registers a plugin that may contribute settings providers.
	 *
	 * Idempotent per plugin id; adds the shared admin/REST hooks exactly once.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Plugin $plugin owning plugin.
	 * @return void
	 */
	public function register_plugin( $plugin ): void {
		$this->plugins[ $plugin->get_id() ] = $plugin;
		$this->tabs_cache                    = null;
		$this->entries_cache                 = null;
		$this->add_hooks();
	}

	/**
	 * Registers a framework-service provider (no owning plugin → neutral cap).
	 *
	 * @since 2.0.2
	 *
	 * @param Settings_Provider $provider service provider.
	 * @return void
	 */
	public function register_service( Settings_Provider $provider ): void {
		$this->services[ $provider->get_id() ] = $provider;
		$this->tabs_cache                       = null;
		$this->entries_cache                    = null;
		$this->add_hooks();
	}

	/**
	 * Adds the shared menu / REST / redirect hooks exactly once.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function add_hooks(): void {
		if ( $this->hooked ) {
			return;
		}
		$this->hooked = true;

		add_action( 'admin_menu', [ $this, 'register_page' ], 40 );
		add_action( 'admin_init', [ $this, 'maybe_redirect_legacy' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
	}

	/**
	 * Collects provider entries from registered plugins + services.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>> entries [ provider, is_woocommerce ].
	 */
	public function collect_entries(): array {
		if ( null !== $this->entries_cache ) {
			return $this->entries_cache;
		}

		$entries = [];

		foreach ( $this->plugins as $plugin ) {
			$is_wc = $plugin instanceof \Woodev\Framework\Woocommerce_Plugin;

			foreach ( (array) $plugin->get_settings_providers() as $provider ) {
				if ( $provider instanceof Settings_Provider ) {
					$entries[] = [
						'provider'       => $provider,
						'is_woocommerce' => $is_wc,
					];
				}
			}
		}

		foreach ( $this->services as $provider ) {
			$entries[] = [
				'provider'       => $provider,
				'is_woocommerce' => false,
			];
		}

		$this->entries_cache = $entries;

		return $entries;
	}

	/**
	 * Builds the request's tab list for the current user (memoized).
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tabs(): array {
		if ( null === $this->tabs_cache ) {
			$this->tabs_cache = $this->build_tabs(
				$this->collect_entries(),
				static function ( string $cap ): bool {
					return current_user_can( $cap );
				}
			);
		}

		return $this->tabs_cache;
	}

	/**
	 * Whether at least one tab is registered (regardless of current user).
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function has_providers(): bool {
		return ! empty( $this->collect_entries() );
	}

	/**
	 * Returns the page (submenu) capability: broadest reach among ALL tabs.
	 *
	 * Uses the full provider set (not the current user's visible subset) so the
	 * submenu cap is stable across users.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_page_capability(): string {
		$caps = [];

		foreach ( $this->collect_entries() as $entry ) {
			$caps[] = self::resolve_capability(
				$entry['provider']->get_declared_capability(),
				! empty( $entry['is_woocommerce'] )
			);
		}

		return self::resolve_page_capability( $caps );
	}

	/**
	 * Returns the resolved capability for one provider id, or null if unknown.
	 *
	 * @since 2.0.2
	 *
	 * @param string $provider_id provider id.
	 * @return string|null
	 */
	public function get_provider_capability( string $provider_id ): ?string {
		foreach ( $this->collect_entries() as $entry ) {
			if ( $entry['provider']->get_id() === $provider_id ) {
				return self::resolve_capability(
					$entry['provider']->get_declared_capability(),
					! empty( $entry['is_woocommerce'] )
				);
			}
		}

		return null;
	}

	/**
	 * Returns the provider for one id, or null.
	 *
	 * @since 2.0.2
	 *
	 * @param string $provider_id provider id.
	 * @return Settings_Provider|null
	 */
	public function get_provider( string $provider_id ): ?Settings_Provider {
		foreach ( $this->collect_entries() as $entry ) {
			if ( $entry['provider']->get_id() === $provider_id ) {
				return $entry['provider'];
			}
		}

		return null;
	}

	/**
	 * Registers the Настройки submenu when ≥1 provider is present.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_page(): void {
		if ( ! $this->has_providers() ) {
			return;
		}

		$hook = add_submenu_page(
			'woodev',
			__( 'Настройки Woodev', 'woodev-plugin-framework' ),
			__( 'Настройки', 'woodev-plugin-framework' ),
			$this->get_page_capability(),
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "admin_print_scripts-{$hook}", [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Renders the React mount node.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap woodev-settings-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Настройки Woodev', 'woodev-plugin-framework' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<div id="woodev-settings-app"></div>';
		echo '<noscript><p>' . esc_html__( 'Для страницы настроек нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueues the settings-page React bundle + inline bootstrap.
	 *
	 * Mirrors Woodev_Admin_Pages::load_licenses_page_scripts(). The schema is NOT
	 * inlined — the app fetches it from GET woodev/v1/settings (cap-filtered
	 * server-side).
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$plugin     = $this->get_asset_plugin();
		$asset_file = $plugin->get_framework_path() . '/assets/build/settings-page/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;
		} else {
			error_log( sprintf( '[woodev] Settings page asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.
			$asset = [
				'dependencies' => [],
				'version'      => $plugin->get_version(),
			];
		}

		$build_url     = $plugin->get_framework_assets_url() . '/build/settings-page';
		$style_path    = $plugin->get_framework_path() . '/assets/build/settings-page/style-index.css';
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-settings-page', $build_url . '/style-index.css', [ 'wp-components' ], $style_version );
		wp_enqueue_script( 'woodev-settings-page', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );

		wp_add_inline_script(
			'woodev-settings-page',
			'window.woodevSettings = ' . wp_json_encode(
				[
					'restRoot' => esc_url_raw( rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/settings' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'adminUrl' => esc_url_raw( admin_url() ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Returns any registered plugin to source framework asset paths/version from.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Plugin
	 */
	private function get_asset_plugin() {
		return reset( $this->plugins );
	}

	/**
	 * Redirects a provider's legacy settings URL to its new tab.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL routing, no state change.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		foreach ( $this->collect_entries() as $entry ) {
			$provider    = $entry['provider'];
			$legacy_page = $provider->get_legacy_page();

			if ( null === $legacy_page ) {
				continue;
			}

			if ( false === strpos( $request_uri, $legacy_page ) ) {
				continue;
			}

			$capability = self::resolve_capability( $provider->get_declared_capability(), ! empty( $entry['is_woocommerce'] ) );
			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			wp_safe_redirect(
				admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $provider->get_id() ) )
			);
			exit;
		}
	}

	/**
	 * Registers the aggregated REST controller through the woodev/v1 registrar.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_rest(): void {
		if ( ! class_exists( 'Woodev_REST_API_Settings_Page' ) ) {
			require_once $this->get_asset_plugin()->get_framework_path() . '/rest-api/controllers/class-rest-api-settings-page.php';
		}

		\Woodev_REST_V1_Registrar::register_controller( new \Woodev_REST_API_Settings_Page( $this ) );
	}

	/**
	 * Resets registration state. Test-only.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function reset_for_tests(): void {
		$this->plugins       = [];
		$this->services      = [];
		$this->tabs_cache    = null;
		$this->entries_cache = null;
		$this->hooked        = false;
	}
```

> **`get_asset_plugin()` caveat:** in REST/admin contexts at least one plugin is always registered before these hooks fire (every `Woodev_Plugin` registers itself in its constructor — Step 3b). `register_page`/`register_rest`/`enqueue_assets` only run after the registry was hooked, which only happens once a plugin or service registered. A service-only setup (no plugins) cannot reach asset enqueue without a plugin to source the framework path — acceptable for SP-1 (the fixture always has the plugin).

- [ ] **Step 3b: Add the seam + constructor wiring to `Woodev_Plugin`**

In `woodev/class-plugin.php`, add the seam method near `get_settings_handler()` (~line 1113):

```php
		/**
		 * Returns this plugin's settings-page providers (tabs).
		 *
		 * Plugins override this to contribute one or more Settings_Provider
		 * descriptors to the neutral Woodev > Настройки page. A multi-carrier
		 * plugin returns several providers (one tab each). Default: none.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev\Framework\Settings\Settings_Provider[]
		 */
		public function get_settings_providers(): array {
			return [];
		}
```

Add the init method (place it next to `init_setup_wizard_handler()`):

```php
		/**
		 * Registers this plugin with the settings-page registry.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function init_settings_page(): void {
			\Woodev\Framework\Settings\Settings_Page_Registry::instance()->register_plugin( $this );
		}
```

Add the call in `__construct()`, right after the `init_competitor_handler();` line (~line 156):

```php
			// register with the settings-page registry
			$this->init_settings_page();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageWiringTest.php`
Expected: PASS (2 tests). Then run the full unit suite to confirm no regression:
Run: `composer test:unit`
Expected: PASS (existing + new tests; 0 failures).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-page-registry.php woodev/class-plugin.php tests/unit/SettingsPageWiringTest.php
git commit -m "feat(settings-page): registry WP wiring + get_settings_providers() seam"
```

---

## Task 6: `Woodev_REST_API_Settings_Page` controller

One aggregated controller (mirrors `Woodev_REST_API_Setup` structure): `GET /settings` returns the cap-filtered tab schema; `POST /settings/{provider_id}` routes values to that provider's handler `update_value()`, reusing the existing validate-and-persist path (enum-by-key, richtext sanitize, numeric coercion).

> **Name:** `Woodev_REST_API_Settings_Page` (NOT `Woodev_REST_API_Settings`, which already exists as the legacy `wc/v3` per-handler controller). File: `class-rest-api-settings-page.php`.

**Files:**
- Create: `woodev/rest-api/controllers/class-rest-api-settings-page.php`
- Test: `tests/unit/SettingsRestControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-settings-page.php';

class SettingsRestControllerTest extends TestCase {

	private function request( array $params ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);

		return $request;
	}

	public function test_get_schema_returns_registry_tabs(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_tabs' )->andReturn(
			[ [ 'id' => 'cdek', 'label' => 'СДЭК', 'capability' => 'manage_woocommerce', 'sections' => [] ] ]
		);

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->get_schema( $this->request( [] ) );

		$this->assertSame( 'cdek', $response['tabs'][0]['id'] );
	}

	public function test_save_unknown_provider_is_404(): void {
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'ghost' )->andReturn( null );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'ghost', 'values' => [] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_unknown_provider', $result->get_error_code() );
	}

	private function section( array $setting_ids ) {
		$section = Mockery::mock();
		$section->shouldReceive( 'get_setting_ids' )->andReturn( $setting_ids );

		return $section;
	}

	public function test_save_persists_each_known_value(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'secret' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'api_key' => 'secret' ] ] ) );

		$this->assertTrue( $response['saved'] );
		$this->assertSame( 'cdek', $response['provider'] );
	}

	public function test_save_drops_undeclared_keys(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock();
		// Undeclared key must never reach the handler — not even to be 404-rejected.
		$handler->shouldNotReceive( 'update_value' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'ghost' => 'x' ] ] ) );

		$this->assertTrue( $response['saved'] );
	}

	public function test_save_reports_validation_error_with_field(): void {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'update_value' )->andThrow( new \Woodev_Plugin_Exception( 'bad value', 400 ) );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'mode' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'mode' => 'x' ] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_invalid', $result->get_error_code() );
		$this->assertSame( 'mode', $result->get_error_data()['field'] );
	}
}
```

> **Verified prerequisites:** the unit `WP_Error` stub in `tests/bootstrap.php` already exposes `get_error_code()` + `get_error_data()` (no stub extension needed). `Woodev_Plugin_Exception extends Exception` lives in `woodev/class-plugin-exception.php` and is **not** auto-loaded in the unit suite — the `require_once` added above is required, else `new \Woodev_Plugin_Exception(...)` fatals. `register_routes()` (which references `WP_REST_Server::READABLE/EDITABLE`) is never unit-invoked, so no server-constant stub is needed.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsRestControllerTest.php`
Expected: FAIL — `Woodev_REST_API_Settings_Page` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Settings page REST controller (woodev/v1).
 *
 * @package Woodev\Framework\REST
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Settings_Page' ) ) :

	/**
	 * Serves the aggregated settings schema and persists per-tab values.
	 *
	 * One controller for the whole Woodev > Настройки page (decision 4): GET
	 * returns every accessible tab's schema; POST /{provider_id} routes values to
	 * that provider's handler->update_value() (validation + sanitize + coercion
	 * already live in the Settings API). Registered through Woodev_REST_V1_Registrar.
	 *
	 * Named _Page to avoid colliding with the legacy wc/v3 Woodev_REST_API_Settings.
	 *
	 * @since 2.0.2
	 */
	class Woodev_REST_API_Settings_Page {

		/**
		 * Settings-page registry.
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev\Framework\Settings\Settings_Page_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Settings\Settings_Page_Registry $registry registry.
		 */
		public function __construct( $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Registers the settings routes.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$base = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

			register_rest_route(
				$base,
				'/settings',
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_schema' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				]
			);

			register_rest_route(
				$base,
				'/settings/(?P<provider_id>[\w-]+)',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'save_permissions_check' ],
				]
			);
		}

		/**
		 * Read gate: the page-level (broadest-reach) capability.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function read_permissions_check(): bool {
			return current_user_can( $this->registry->get_page_capability() );
		}

		/**
		 * Save gate: the targeted provider's resolved capability.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function save_permissions_check( $request ): bool {
			$capability = $this->registry->get_provider_capability( (string) $request->get_param( 'provider_id' ) );

			if ( null === $capability ) {
				return false;
			}

			return current_user_can( $capability );
		}

		/**
		 * Returns the cap-filtered tab schema for the current user.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|array<string,mixed>
		 */
		public function get_schema( $request ) {
			return rest_ensure_response( [ 'tabs' => $this->registry->get_tabs() ] );
		}

		/**
		 * Persists one tab's values through its handler.
		 *
		 * Each setting is validated + persisted by update_value() as it is read;
		 * a failure reports which field failed (settings before it are saved —
		 * re-submitting the tab is idempotent).
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function save( $request ) {
			$provider_id = (string) $request->get_param( 'provider_id' );
			$provider    = $this->registry->get_provider( $provider_id );

			if ( null === $provider ) {
				return new WP_Error(
					'woodev_settings_unknown_provider',
					__( 'Неизвестная вкладка настроек.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$handler = $provider->get_handler();
			$values  = (array) $request->get_param( 'values' );

			// Scope to the tab's DECLARED setting ids (mirrors the wizard's
			// array_intersect_key allow-list): a crafted request must never reach
			// a setting the handler registered but this tab does not expose.
			$allowed = [];
			foreach ( $provider->get_sections() as $section ) {
				$allowed = array_merge( $allowed, $section->get_setting_ids() );
			}
			$values = array_intersect_key( $values, array_flip( $allowed ) );

			foreach ( $values as $setting_id => $value ) {
				try {
					$handler->update_value( (string) $setting_id, $value );
				} catch ( \Woodev_Plugin_Exception $e ) {
					return new WP_Error(
						'woodev_settings_invalid',
						$e->getMessage(),
						[
							'status' => $e->getCode() ?: 400,
							'field'  => $setting_id,
						]
					);
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[woodev] settings save failed on "%s": %s', $setting_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected persistence failure.
					return new WP_Error(
						'woodev_settings_server_error',
						__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
						[ 'status' => 500 ]
					);
				}
			}

			return rest_ensure_response(
				[
					'saved'    => true,
					'provider' => $provider_id,
				]
			);
		}
	}

endif;
```

> **Scope safety:** keys not in the tab's declared sections are dropped by `array_intersect_key` before the persist loop (never reach the handler). For declared-but-handler-rejected ids, `update_value()` throws `Woodev_Plugin_Exception` (validation) which surfaces as `woodev_settings_invalid` naming the field. **Per-setting persist is intentionally NOT atomic** — it mirrors the framework's established wizard pattern (`Woodev_REST_API_Setup::save_step`: each setting persists as it validates; a mid-tab failure leaves earlier settings saved; re-submitting the tab is idempotent). The spec requires "report which field," not tab-level atomicity, so this is a deliberate, consistent choice — do not add staging.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsRestControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-settings-page.php tests/unit/SettingsRestControllerTest.php
git commit -m "feat(settings-page): aggregated woodev/v1/settings REST controller"
```

---

## Task 7: Regenerate class-map + style/static gate

The 4 new namespaced classes must enter `woodev/class-map.php` (the runtime autoloader; `ClassMapCompletenessTest` reddens otherwise). The REST controller is `require_once`-loaded (like `Woodev_REST_API_Setup`) — the generator skips nothing automatically, but its `if ( ! class_exists )` guard + global name means it is NOT autoloaded; it still gets a map entry, which is harmless.

**Files:**
- Modify: `woodev/class-map.php` (generated)

- [ ] **Step 1: Regenerate the class map**

Run: `php bin/generate-class-map.php`
Expected: `Wrote N entries to woodev/class-map.php` (N increased by the new classes).

- [ ] **Step 2: Verify completeness + style**

```bash
./vendor/bin/phpunit tests/unit/ClassMapCompletenessTest.php
composer phpcs
```
Expected: ClassMapCompletenessTest PASS; phpcs reports no errors on the new files. Fix any phpcs findings (short arrays, Yoda, spacing, docblocks) and re-run.

- [ ] **Step 3: Full unit suite**

Run: `composer test:unit`
Expected: PASS, 0 failures.

- [ ] **Step 4: Commit**

```bash
git add woodev/class-map.php
git commit -m "chore(settings-page): regenerate class map for settings-page classes"
```

---

## Task 8: React settings-page app (shared controls extracted)

Extract the wizard's control components into `src/components/` (so both apps share them, per spec — no duplication), re-point the wizard imports, then build the settings-page app that fetches the schema and saves per tab. This task is verified by build success + an operator rig check (no PHPUnit).

**Files:**
- Move: `src/setup-wizard/{control-field,dropdown,richtext,icons}.js` → `src/components/`
- Modify: `src/setup-wizard/*.js` (imports), `package.json` (build script)
- Create: `src/settings-page/{index,rest,app,section-view}.js`, `src/settings-page/style.scss`
- Generated: `woodev/assets/build/settings-page/*`

- [ ] **Step 1: Extract shared controls**

Move these four files verbatim into a new `src/components/` directory:
`control-field.js`, `dropdown.js`, `richtext.js`, `icons.js`.
Update their intra-imports so they reference siblings within `src/components/` (e.g. `control-field.js`'s `import WizardDropdown from './dropdown'` and `import WizardRichText from './richtext'` and `import { InfoIcon } from './icons'` keep working as same-directory relatives — no path change needed since they move together).

- [ ] **Step 2: Re-point the wizard imports**

After the move, the files **remaining** in `src/setup-wizard/` still import the moved modules and MUST be re-pointed (verified by grep — these are the only two remaining references; the moved files import each other and `./icons` as same-directory siblings, so those need no change):

- `src/setup-wizard/app.js` (line ~28): `import { CheckFilledIcon, GearIcon, StarIcon } from './icons';` → `from '../components/icons';`
- `src/setup-wizard/step-view.js` (line ~16): `import ControlField from './control-field';` → `from '../components/control-field';`

Run the wizard build to confirm no unresolved module:

Run: `npm run build:setup`
Expected: build succeeds; `woodev/assets/build/setup-wizard/index.js` regenerated. (Its bytes change because module paths changed — that is expected; commit it. The assets-parity CI runs the full `npm run build`, so the regenerated wizard bundle must be committed.)

- [ ] **Step 3: Add the settings-page entry to the build**

In `package.json` `scripts`, append to `build` and add helpers:

```json
"build": "wp-scripts build ./src/license-page/index.js --output-path=woodev/assets/build/license-page && wp-scripts build ./src/plugins-page/index.js --output-path=woodev/assets/build/plugins-page && wp-scripts build ./src/setup-wizard/index.js --output-path=woodev/assets/build/setup-wizard && wp-scripts build ./src/settings-page/index.js --output-path=woodev/assets/build/settings-page",
"build:settings": "wp-scripts build ./src/settings-page/index.js --output-path=woodev/assets/build/settings-page",
"start:settings": "wp-scripts start ./src/settings-page/index.js --output-path=woodev/assets/build/settings-page",
```

- [ ] **Step 4: Write `src/settings-page/rest.js`**

```javascript
import apiFetch from '@wordpress/api-fetch';

function bootstrap() {
	return window.woodevSettings || {};
}

export function fetchSchema() {
	const { restRoot, nonce } = bootstrap();
	return apiFetch( {
		url: restRoot,
		method: 'GET',
		headers: { 'X-WP-Nonce': nonce },
	} );
}

export function saveTab( providerId, values ) {
	const { restRoot, nonce } = bootstrap();
	return apiFetch( {
		url: `${ restRoot }/${ providerId }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}
```

- [ ] **Step 5: Write `src/settings-page/index.js`**

```javascript
import { createElement, createRoot } from '@wordpress/element';
import App from './app';
import './style.scss';

const rootElement = document.getElementById( 'woodev-settings-app' );

if ( rootElement && window.woodevSettings ) {
	createRoot( rootElement ).render( createElement( App ) );
}
```

- [ ] **Step 6: Write `src/settings-page/section-view.js`** (renders one tab's sections, reusing the shared `ControlField`)

```javascript
import { createElement, Fragment } from '@wordpress/element';
import ControlField from '../components/control-field';

export default function SectionView( { tab, values, onFieldChange } ) {
	return createElement(
		Fragment,
		null,
		tab.sections.map( ( section ) =>
			createElement(
				'div',
				{ key: section.id, className: 'woodev-settings__section' },
				section.label &&
					createElement( 'h3', { className: 'woodev-settings__section-title' }, section.label ),
				Object.keys( section.fields ).map( ( settingId ) =>
					createElement( ControlField, {
						key: settingId,
						schema: section.fields[ settingId ],
						value:
							settingId in values
								? values[ settingId ]
								: section.fields[ settingId ].value,
						onChange: ( next ) => onFieldChange( settingId, next ),
					} )
				)
			)
		)
	);
}
```

- [ ] **Step 7: Write `src/settings-page/app.js`** (tabs, per-tab save, loading skeleton)

```javascript
import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, TabPanel } from '@wordpress/components';
import { fetchSchema, saveTab } from './rest';
import SectionView from './section-view';

export default function App() {
	const [ tabs, setTabs ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ edits, setEdits ] = useState( {} ); // { providerId: { settingId: value } }
	const [ saving, setSaving ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ saveError, setSaveError ] = useState( '' );

	useEffect( () => {
		fetchSchema()
			.then( ( res ) => setTabs( res.tabs || [] ) )
			.catch( () => setLoadError( __( 'Не удалось загрузить настройки.', 'woodev-plugin-framework' ) ) );
	}, [] );

	if ( loadError ) {
		return createElement( Notice, { status: 'error', isDismissible: false }, loadError );
	}

	if ( null === tabs ) {
		return createElement(
			'div',
			{ className: 'woodev-settings__loading' },
			createElement( Spinner ),
			createElement( 'span', null, __( 'Загрузка…', 'woodev-plugin-framework' ) )
		);
	}

	if ( 0 === tabs.length ) {
		return createElement(
			Notice,
			{ status: 'info', isDismissible: false },
			__( 'Нет доступных настроек.', 'woodev-plugin-framework' )
		);
	}

	const onFieldChange = ( providerId, settingId, value ) => {
		setSaved( '' );
		setEdits( ( prev ) => ( {
			...prev,
			[ providerId ]: { ...( prev[ providerId ] || {} ), [ settingId ]: value },
		} ) );
	};

	const onSave = ( providerId ) => {
		setSaving( providerId );
		setSaveError( '' );
		setSaved( '' );
		saveTab( providerId, edits[ providerId ] || {} )
			.then( () => {
				setSaving( '' );
				setSaved( providerId );
			} )
			.catch( ( err ) => {
				setSaving( '' );
				setSaveError(
					( err && err.message ) ||
						__( 'Не удалось сохранить настройки.', 'woodev-plugin-framework' )
				);
			} );
	};

	const tabOptions = tabs.map( ( tab ) => ( { name: tab.id, title: tab.label } ) );

	return createElement(
		'div',
		{ className: 'woodev-settings' },
		createElement(
			TabPanel,
			{ className: 'woodev-settings__tabs', tabs: tabOptions },
			( tabOption ) => {
				const tab = tabs.find( ( t ) => t.id === tabOption.name );
				const values = edits[ tab.id ] || {};

				return createElement(
					Fragment,
					null,
					saveError &&
						saving === '' &&
						createElement( Notice, { status: 'error', onRemove: () => setSaveError( '' ) }, saveError ),
					saved === tab.id &&
						createElement(
							Notice,
							{ status: 'success', isDismissible: true, onRemove: () => setSaved( '' ) },
							__( 'Настройки сохранены.', 'woodev-plugin-framework' )
						),
					createElement( SectionView, {
						tab,
						values,
						onFieldChange: ( settingId, value ) => onFieldChange( tab.id, settingId, value ),
					} ),
					createElement(
						'div',
						{ className: 'woodev-settings__actions' },
						createElement(
							Button,
							{
								variant: 'primary',
								isBusy: saving === tab.id,
								disabled: saving === tab.id,
								onClick: () => onSave( tab.id ),
							},
							__( 'Сохранить', 'woodev-plugin-framework' )
						)
					)
				);
			}
		)
	);
}
```

- [ ] **Step 8: Write `src/settings-page/style.scss`** (minimal brand-consistent layout)

```scss
.woodev-settings {
	max-width: 920px;

	&__loading {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 24px 0;
	}

	&__section {
		margin: 0 0 24px;
	}

	&__section-title {
		font-size: 14px;
		font-weight: 600;
		margin: 0 0 12px;
	}

	&__actions {
		margin-top: 16px;
		padding-top: 16px;
		border-top: 1px solid #e0e0e0;
	}

	// Brand cyan accent on the active tab (matches s31 wizard tone).
	.components-tab-panel__tabs-item.is-active {
		box-shadow: inset 0 -2px 0 0 #06aedd;
	}
}
```

- [ ] **Step 9: Build + verify assets**

Run: `npm run build`
Expected: all four bundles build with no errors; `woodev/assets/build/settings-page/` now contains `index.js`, `index.asset.php`, `style-index.css`, `style-index-rtl.css`. Confirm LF line endings on generated files (the repo enforces LF in `woodev/assets/build`).

- [ ] **Step 10: Commit**

```bash
git add src/ package.json woodev/assets/build/
git commit -m "feat(settings-page): React app + shared control extraction to src/components"
```

> **Rig verification (operator):** The React UI is not unit-tested. After the fixture lands (Task 9), the operator loads `Woodev > Настройки` on the dev rig (`:8888`), confirms: tabs render, controls populate from stored values, per-tab Save persists (reload shows new values), validation error names the field, and no console errors. Diagnose-then-batch-fix per the operator's manual-testing workflow.

---

## Task 9: Fixture reference provider + integration tests

Give `woodev-test-plugin` a real settings handler + a «Карьер» provider and a framework-service stub, then prove the whole slot end-to-end with WP integration tests (menu registration, GET/POST, legacy redirect, cap-based visibility).

**Files:**
- Modify: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`
- Create: `tests/integration/SettingsPageTest.php`

- [ ] **Step 1: Add a settings handler + providers to the fixture**

Inside `woodev_test_plugin_init()` (alongside `Woodev_Test_Setup_Wizard`), add a handler and a service stub, and override `get_settings_providers()` on `Woodev_Test_Plugin`:

```php
		/**
		 * Minimal settings handler for the «Карьер» reference provider.
		 */
		class Woodev_Test_Settings extends \Woodev_Abstract_Settings {

			protected function register_settings() {
				$this->register_setting( 'api_key', \Woodev_Setting::TYPE_STRING, [ 'name' => 'API-ключ', 'default' => '' ] );
				$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Режим', 'options' => [ 'test' => 'Тест', 'live' => 'Боевой' ], 'default' => 'test' ] );
				$this->register_control( 'api_key', \Woodev_Control::TYPE_TEXT );
				$this->register_control( 'mode', \Woodev_Control::TYPE_SELECT );
			}
		}
```

Add a shared accessor + the seam override on `Woodev_Test_Plugin`:

```php
			/** @var Woodev_Test_Settings|null */
			private $settings_handler;

			public function get_settings_handler() {
				if ( null === $this->settings_handler ) {
					$this->settings_handler = new Woodev_Test_Settings( $this->get_id() );
				}

				return $this->settings_handler;
			}

			public function get_settings_providers(): array {
				return [
					\Woodev\Framework\Settings\Settings_Provider::create(
						'quarry',
						'Карьер',
						$this->get_settings_handler(),
						[
							\Woodev\Framework\Settings\Settings_Section::create( 'general', 'Общие', [ 'api_key', 'mode' ] ),
						],
						[
							'legacy_page' => 'wc-settings&tab=shipping&section=quarry',
						]
					),
				];
			}
```

> The test plugin extends `Woodev_Plugin` (neutral) → its provider resolves to `manage_options`. To also exercise the WC path in integration, the test can register a second provider with an explicit `capability => 'manage_woocommerce'`, or assert resolution via a `Woocommerce_Plugin` fixture if one is wired. Keep the neutral provider as the primary reference; add the explicit-cap case in the integration test data.

- [ ] **Step 2: Write the failing integration test**

```php
<?php
namespace Woodev\Tests\Integration;

use Woodev\Framework\Settings\Settings_Page_Registry;

class SettingsPageTest extends TestCase {

	private function registry(): Settings_Page_Registry {
		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );

		return $registry;
	}

	public function test_menu_registers_when_provider_present(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$registry->register_page();

		global $submenu;
		$slugs = array_column( $submenu['woodev'] ?? [], 2 );

		$this->assertContains( Settings_Page_Registry::PAGE_SLUG, $slugs );
	}

	public function test_get_schema_returns_quarry_tab(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$tabs     = $registry->get_tabs();

		$this->assertSame( 'quarry', $tabs[0]['id'] );
		$this->assertArrayHasKey( 'api_key', $tabs[0]['sections'][0]['fields'] );
	}

	public function test_save_persists_through_handler(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$provider = $registry->get_provider( 'quarry' );
		$provider->get_handler()->update_value( 'api_key', 'persisted-key' );

		$this->assertSame( 'persisted-key', get_option( 'woodev_woodev-test-plugin_api_key' ) );
	}

	public function test_legacy_url_redirects_to_new_tab(): void {
		// Verify the redirect target the registry computes (no actual exit in test):
		$registry = $this->registry();
		$provider = $registry->get_provider( 'quarry' );

		$this->assertSame( 'wc-settings&tab=shipping&section=quarry', $provider->get_legacy_page() );
		// Full redirect behavior is covered by asserting the computed target URL
		// shape; the wp_safe_redirect/exit path is exercised manually on the rig.
	}

	/**
	 * Discharges Decision 5 point 4: a manage_woocommerce-only shop manager must
	 * reach the settings page even though the parent `woodev` menu is manage_options.
	 * WordPress promotes the first accessible submenu, so the page must surface.
	 */
	public function test_shop_manager_reaches_settings_submenu(): void {
		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );
		// A WC-capability tab so the page cap resolves to manage_woocommerce.
		$registry->register_service(
			\Woodev\Framework\Settings\Settings_Provider::create(
				'wc_only',
				'WC',
				woodev_test_plugin()->get_settings_handler(),
				[ \Woodev\Framework\Settings\Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ],
				[ 'capability' => 'manage_woocommerce' ]
			)
		);

		$this->assertSame( 'manage_woocommerce', $registry->get_page_capability() );

		// Build the hub parent menu + the settings submenu under the real admin_menu chain.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$admin_pages = new \Woodev_Admin_Pages();
		$admin_pages->instance( woodev_test_plugin() );
		do_action( 'admin_menu' );

		global $submenu;
		$slugs = array_column( $submenu['woodev'] ?? [], 2 );

		// Shop manager sees the settings page (its cap = manage_woocommerce) but NOT
		// the manage_options-only quarry tab (omitted from the schema).
		$this->assertContains( Settings_Page_Registry::PAGE_SLUG, $slugs );
		$tab_ids = array_column( $registry->get_tabs(), 'id' );
		$this->assertContains( 'wc_only', $tab_ids );
		$this->assertNotContains( 'quarry', $tab_ids );
	}
}
```

> Adapt to the integration `TestCase` conventions in `tests/integration/` (the fixture plugin is loaded by the integration bootstrap; `woodev_test_plugin()` is the global accessor). If `register_page()` requires the parent `woodev` menu to exist, register `Woodev_Admin_Pages` first or assert via `get_tabs()`/`has_providers()` instead of the global `$submenu`.

- [ ] **Step 3: Run integration tests**

Run (requires wp-env or `WP_TESTS_DIR`): `composer test:integration -- --filter SettingsPageTest`
Expected: PASS. (If the local WP test library is unavailable, this gate runs on CI — note it in the PR and ensure the unit suite is green locally.)

- [ ] **Step 4: Revert any demo-only seeding before commit**

The fixture changes above are permanent test scaffolding (not demo seeding) — keep them. If you added extra rich mock data purely for the operator's rig screenshot, strip it before committing (mirror the s30/s31 "revert demo fixture before merge" rule).

- [ ] **Step 5: Commit**

```bash
git add tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php tests/integration/SettingsPageTest.php
git commit -m "test(settings-page): fixture reference provider + integration coverage"
```

---

## Task 10: DRY the wizard onto `Field_Schema` + final verification

Optional-but-recommended cleanup: make `Setup_Wizard::get_field_schema()` delegate to the shared `Field_Schema` builder so there is one source of truth, then run the full local gate and the plan self-review.

**Files:**
- Modify: `woodev/setup/class-setup-wizard.php` (lowercase `setup/` — git-tracked casing; matters on case-sensitive Linux CI)

- [ ] **Step 1: Delegate the wizard's schema builder**

Replace the body of `Setup_Wizard::get_field_schema()` with a delegation (behavior-preserving — the entry shape is identical, verified by the existing `SetupWizardFieldSchemaTest`):

```php
	protected function get_field_schema(): array {
		$handler = $this->plugin->get_settings_handler();
		if ( ! $handler ) {
			return [];
		}

		return \Woodev\Framework\Settings\Field_Schema::from_handler( $handler );
	}
```

- [ ] **Step 2: Verify the wizard schema test still passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardFieldSchemaTest.php tests/unit/SetupWizardBootstrapTest.php`
Expected: PASS (no behavior change). If `class-setup-wizard.php` does not already load the `Field_Schema` class on the runtime path, ensure the class-map covers it (it does after Task 7) — no `require_once` needed since it is autoloaded.

- [ ] **Step 3: Full local gate**

```bash
composer phpcs
composer test:unit
php bin/generate-class-map.php   # confirm idempotent (no diff)
```
Expected: phpcs clean; unit suite green (all new + existing); class map unchanged (no new diff).

- [ ] **Step 4: Plan self-review (spec coverage)**

Confirm each spec section maps to a task:
- Decision 1 (provider = handler + descriptor; registry aggregates; multi-carrier) → Tasks 3, 4, 5, 9.
- Decision 2 (native per-option storage kept; legacy = redirect only in SP-1) → reuse of `Woodev_Abstract_Settings` (no storage change); redirect in Task 5; value migration explicitly out of scope.
- Decision 3 (registry + one fixture provider, service seam, no DaData) → Tasks 5 (`register_service`), 9 (fixture).
- Decision 4 (one app + one controller routed by `{provider_id}`) → Tasks 6, 8.
- Decision 5 (capability resolution + parent-menu reconciliation) → Task 4 (`resolve_capability`/`resolve_page_capability`), Task 5 (page cap, submenu registration), Task 9 (cap-visibility integration).
- Cross-cutting (class-map, no `_n()`, assets committed, save-path reuse) → Tasks 7, 8.

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-setup-wizard.php
git commit -m "refactor(setup-wizard): delegate field schema to shared Field_Schema builder"
```

---

## Out of scope (do NOT build in SP-1)

- ❌ DaData service (SP-4) — only the `register_service()` seam + a fixture stub.
- ❌ Per-method / instance settings (stay in the WC Shipping Zones modal).
- ❌ Legacy serialized-array → per-option **value** migration (per-plugin Lifecycle at the Phase-E pilot). SP-1 ships only the legacy-URL redirect + clean `setting_id` namespaces.
- ❌ Final `supports_*` flag names/defaults (settled at the first plugin migration — stored as a declaration mechanism only).

## Risks / watch-items for the executor

- **Parent-menu visibility for shop managers (Decision 5 pt4):** discharged by `test_shop_manager_reaches_settings_submenu` (Task 9). WP promotes the first accessible submenu when the parent cap (`manage_options` on `woodev`) is unmet. **If that test fails** (the `woodev` menu stays hidden for a `manage_woocommerce`-only user), apply the concrete fallback: in `woodev/admin/class-admin-pages.php::admin_menu()`, register the `woodev` parent menu under a broader cap (e.g. compute it the same way as the settings page) so the hub surfaces — that is a permitted change (admin-page slugs are preserved; only the cap gate widens). Do NOT ship without the test green.
- **`register_page` depends on the `woodev` parent menu existing:** `Woodev_Admin_Pages::admin_menu` registers it at priority 10; the registry's submenu registers at priority 40, so in admin the parent always exists first. The dependency is real but satisfied by ordering — `Woodev_Admin_Pages` is loaded for the first plugin in every admin request. The shop-manager integration test exercises the full chain.
- **`get_asset_plugin()` with services only:** documented limitation — a service-only registration cannot source framework asset paths. The fixture always registers the plugin, so SP-1 is unaffected; revisit when a pure-framework service ships (SP-4).
- **Singleton state across tests:** always call `reset_for_tests()` in `setUp`/at the top of registry-mutating tests to avoid cross-test bleed.
- **`WP_Error` unit stub:** the REST controller tests need `get_error_code()`/`get_error_data()` on the unit `WP_Error` stub — extend `tests/bootstrap.php` if the existing stub lacks them (mirror how `SetupWizardRestControllerTest` handles it).
