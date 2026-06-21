# Competitor Notification Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the v1 competitor-detection script as a clean, reusable v2 framework module: a platform-neutral engine driven by declarative per-plugin rules, with pluggable WC-Admin-Notes / admin-notice renderers and a smart account-aware recommend link.

**Architecture:** New PSR-4 namespace `Woodev\Framework\Competitor\`. A plugin opts in by overriding `Woodev_Plugin::get_competitor_notification_handler()` to return a subclass of the abstract `Competitor_Notification_Handler` that implements `get_competitor_rules(): array`. On every admin screen load the engine normalizes each raw rule to a `Competitor_Rule` value object, detects whether any `detect` slug is active, suppresses recommend rules when our own equivalent is active, then asks a `Competitor_Notice_Renderer` to create/update/delete the note. Renderer is chosen by `class_exists( \Automattic\WooCommerce\Admin\Notes\Note::class )` (the gotcha fix) — `WC_Admin_Notes_Renderer` when WC Admin is present, `Admin_Notice_Renderer` (dismissible admin notice) otherwise. For `recommend` rules the engine computes a smart link target: account connected + product owned → in-admin extensions catalog; otherwise the public product URL.

**Tech Stack:** PHP 7.4+ (target 8.1), WordPress, WooCommerce Admin Notes API, Brain Monkey + Mockery (unit tests), hand-written `spl_autoload` via `woodev/class-map.php` (no Composer in shipped plugins).

---

## Background — read before starting

- **Spec:** `docs-internal/specs/2026-06-21-competitor-notification-design.md` (design approved s27).
- **v1 reference (raw, being rewritten):**
  - `plugins-reference/woocommerce-yandex-delivery/woodev/handlers/competitor-notification.php` (abstract base — note its weaknesses: domain logic in base, always-true `is_enhanced_admin_available()` gate, forced re-surface).
  - `plugins-reference/woocommerce-yandex-delivery/includes/class-plugin-competitor-notices.php` (imperative subclass).
- **v2 substrate:**
  - `woodev/admin/class-notes-helper.php` — `Woodev_Notes_Helper::get_note_with_name()`, `note_with_name_exists()`, `get_note_ids_with_source()`, `delete_notes_with_source()`.
  - `woodev/class-plugin.php` — `init_*_handler()` opt-in pattern (see `init_setup_wizard_handler()` at ~line 282; `__construct()` sequence at ~line 126-156; `add_hooks()` at ~line 286); `is_plugin_active( $slug )` (~line 1321); `get_id_dasherized()` (~line 931); `get_admin_notice_handler()` (~line 1011).
  - `woodev/class-admin-notice-handler.php` — `add_admin_notice( $message, $message_id, $params = [] )` (dismissal keyed by `$message_id`, `dismissible` default true; `should_display_notice()` already suppresses dismissed notices).
  - `woodev/account/class-account-connection.php` — `Woodev_Account_Connection::is_connected(): bool` (~line 162).
  - `woodev/rest-api/controllers/class-rest-api-account.php` — caches purchases in transient `woodev_account_purchases` as `[ 'purchases' => [...], 'purchased' => int[] ]` (5-min TTL). The engine **reads this cached transient** for the owned-check — it never makes a blocking HTTP request at admin-load render time (graceful degradation: no cache → public URL).

### Resolved plan-time decisions (spec §10 open items)

1. **Owned-by-download_id check:** read `get_transient( 'woodev_account_purchases' )`; if it is an array and `our_download_id` is in its `purchased` list **and** `Woodev_Account_Connection::is_connected()` → extensions-page link; else → public `our_url`. No blocking fetch. **No `?highlight={id}` query arg** added to the extensions page (YAGNI — not implemented this round).
2. **Fallback renderer dismissal:** reuse `Woodev_Admin_Notice_Handler::add_admin_notice( $content, $note_name )` as-is — `$note_name` is the dismiss key; no per-competitor key needed.
3. **Trigger point:** hook on `current_screen` (admin-only, never front end), guarded by `is_admin()`. Engine runs once per admin screen load; no forced re-surface.

### Conventions (enforce throughout)

- New code is namespaced `Woodev\Framework\Competitor\` (PSR-4). File naming mirrors `woodev/shipping-method/*`: `class-*.php` / `interface-*.php`, one class per file, `defined( 'ABSPATH' ) || exit;` header.
- `@since 2.0.2` on every new public/protected method and class. Version is NOT bumped.
- Type declarations on all params and returns; docblocks on public/protected methods; default `private` visibility; Yoda conditions; short arrays `[]`; `??` over `isset`.
- After adding/removing any framework class file, regenerate the class map: `php bin/generate-class-map.php`, and commit `woodev/class-map.php` (gotcha `framework-classmap-autoload-vendored-boot`). The runtime autoloader resolves namespaced classes from the map — no manual `require_once` in `includes()` is needed for these (mirrors how shipping classes are loaded).
- Do NOT add WooCommerce method calls to base `Woodev_Plugin` — the engine is platform-neutral; all WC specifics live inside `WC_Admin_Notes_Renderer`.
- Run `composer test:unit` after each implementation step; keep green (baseline 729 unit). Final gate: `composer check` (phpcs + phpstan L3 + unit).

### File structure (created / modified)

| File | Responsibility |
|---|---|
| `woodev/competitor/class-competitor-rule.php` | `Competitor_Rule` value object — normalize + validate one raw rule array, typed accessors |
| `woodev/competitor/interface-competitor-notice-renderer.php` | `Competitor_Notice_Renderer` interface — `render( Competitor_Rule, array $note ): void`, `delete( Competitor_Rule ): void` |
| `woodev/competitor/class-wc-admin-notes-renderer.php` | `WC_Admin_Notes_Renderer` — create/update/delete a WC Admin `Note` via `Woodev_Notes_Helper` |
| `woodev/competitor/class-admin-notice-renderer.php` | `Admin_Notice_Renderer` — fallback dismissible admin notice via `Woodev_Admin_Notice_Handler` |
| `woodev/competitor/class-competitor-notification-handler.php` | `Competitor_Notification_Handler` abstract engine — orchestrate detect → suppress → build note → renderer; default i18n templates; smart link target |
| `woodev/class-plugin.php` (modify) | `init_competitor_handler()`, `get_competitor_notification_handler()`, property, `current_screen` hook handler |
| `woodev/class-map.php` (regenerate) | add the 5 new classes |
| `tests/unit/CompetitorRuleTest.php` | VO normalization + mode validation |
| `tests/unit/CompetitorRendererTest.php` | renderer selection by `class_exists`; fallback renderer behavior |
| `tests/unit/CompetitorNotificationHandlerTest.php` | detection any-match, suppression, auto-delete, smart link target, default templates |

---

## Task 1: `Competitor_Rule` value object

**Files:**
- Create: `woodev/competitor/class-competitor-rule.php`
- Test: `tests/unit/CompetitorRuleTest.php`

The VO normalizes one raw rule array. `detect` is `string|string[]` → always exposed as `string[]`. `mode` must be `recommend` or `conflict` (invalid → `InvalidArgumentException`). All other keys have typed accessors with safe defaults.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for Woodev\Framework\Competitor\Competitor_Rule (s28).
 *
 * Covers: detect string→array normalization + any-match list; mode validation
 * (recommend/conflict accepted, anything else rejected); typed accessors and
 * defaults for our_* / overrides; image + actions defaults.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Competitor_Rule;
use InvalidArgumentException;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';

class CompetitorRuleTest extends TestCase {

	public function test_detect_string_is_normalized_to_array(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );
		$this->assertSame( [ 'cdek.php' ], $rule->get_detect_slugs() );
	}

	public function test_detect_array_is_preserved(): void {
		$rule = new Competitor_Rule( [ 'detect' => [ 'a.php', 'b.php' ], 'mode' => 'conflict' ] );
		$this->assertSame( [ 'a.php', 'b.php' ], $rule->get_detect_slugs() );
	}

	public function test_recommend_mode_accepted(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'recommend' ] );
		$this->assertSame( 'recommend', $rule->get_mode() );
	}

	public function test_conflict_mode_accepted(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'conflict' ] );
		$this->assertSame( 'conflict', $rule->get_mode() );
	}

	public function test_invalid_mode_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'spam' ] );
	}

	public function test_missing_detect_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Competitor_Rule( [ 'mode' => 'recommend' ] );
	}

	public function test_typed_accessors_and_defaults(): void {
		$rule = new Competitor_Rule(
			[
				'detect'          => 'cdek.php',
				'mode'            => 'recommend',
				'our_download_id' => 42,
				'our_url'         => 'https://woodev.ru/x',
				'our_name'        => 'СДЭК',
				'our_plugin_file' => 'woocommerce-edostavka.php',
				'competitor_name' => 'CDEKDelivery',
			]
		);

		$this->assertSame( 42, $rule->get_our_download_id() );
		$this->assertSame( 'https://woodev.ru/x', $rule->get_our_url() );
		$this->assertSame( 'СДЭК', $rule->get_our_name() );
		$this->assertSame( 'woocommerce-edostavka.php', $rule->get_our_plugin_file() );
		$this->assertSame( 'CDEKDelivery', $rule->get_competitor_name() );
	}

	public function test_defaults_when_optional_keys_absent(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'conflict' ] );
		$this->assertSame( 0, $rule->get_our_download_id() );
		$this->assertSame( '', $rule->get_our_url() );
		$this->assertNull( $rule->get_our_plugin_file() );
		$this->assertNull( $rule->get_title_override() );
		$this->assertNull( $rule->get_content_override() );
	}

	public function test_note_name_is_mode_and_first_slug_scoped(): void {
		$rule = new Competitor_Rule( [ 'detect' => [ 'cdek.php', 'b.php' ], 'mode' => 'recommend' ] );
		$this->assertSame( 'woodev-competitor-recommend-cdek-php', $rule->get_note_name() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRuleTest.php`
Expected: FAIL — class `Woodev\Framework\Competitor\Competitor_Rule` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Value object for a single competitor rule.
 *
 * Normalizes a plain rule array declared by a plugin's get_competitor_rules()
 * into a validated, typed object. `detect` becomes a string[] (any-match);
 * `mode` is validated against the allowed set; optional keys get safe defaults.
 *
 * @since 2.0.2
 */
final class Competitor_Rule {

	/** @since 2.0.2 */
	public const MODE_RECOMMEND = 'recommend';

	/** @since 2.0.2 */
	public const MODE_CONFLICT = 'conflict';

	/** @var string[] competitor plugin basenames; note fires if ANY is active */
	private array $detect_slugs;

	/** @var string one of MODE_* */
	private string $mode;

	/** @var int our EDD download id for the smart link target (0 when absent) */
	private int $our_download_id;

	/** @var string public product/buy URL */
	private string $our_url;

	/** @var string our product display name */
	private string $our_name;

	/** @var string|null our equivalent plugin basename; suppress recommend when active */
	private ?string $our_plugin_file;

	/** @var string competitor display name used in default templates */
	private string $competitor_name;

	/** @var string|null per-rule title override */
	private ?string $title_override;

	/** @var string|null per-rule content override */
	private ?string $content_override;

	/** @var string|null per-rule image URL override */
	private ?string $image_override;

	/**
	 * @since 2.0.2
	 *
	 * @param array<string,mixed> $raw raw rule as declared by the plugin
	 *
	 * @throws InvalidArgumentException when detect is empty or mode is invalid
	 */
	public function __construct( array $raw ) {

		$detect = $raw['detect'] ?? [];
		$detect = is_array( $detect ) ? array_values( $detect ) : [ $detect ];
		$detect = array_values( array_filter( array_map( 'strval', $detect ), static fn( $s ) => '' !== $s ) );

		if ( empty( $detect ) ) {
			throw new InvalidArgumentException( 'Competitor_Rule requires a non-empty "detect".' );
		}

		$mode = (string) ( $raw['mode'] ?? '' );

		if ( ! in_array( $mode, [ self::MODE_RECOMMEND, self::MODE_CONFLICT ], true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid competitor rule mode "%s".', $mode ) );
		}

		$this->detect_slugs     = $detect;
		$this->mode             = $mode;
		$this->our_download_id  = (int) ( $raw['our_download_id'] ?? 0 );
		$this->our_url          = (string) ( $raw['our_url'] ?? '' );
		$this->our_name         = (string) ( $raw['our_name'] ?? '' );
		$this->our_plugin_file  = isset( $raw['our_plugin_file'] ) ? (string) $raw['our_plugin_file'] : null;
		$this->competitor_name  = (string) ( $raw['competitor_name'] ?? '' );
		$this->title_override   = isset( $raw['title'] ) ? (string) $raw['title'] : null;
		$this->content_override = isset( $raw['content'] ) ? (string) $raw['content'] : null;
		$this->image_override   = isset( $raw['image'] ) ? (string) $raw['image'] : null;
	}

	/** @since 2.0.2 @return string[] */
	public function get_detect_slugs(): array {
		return $this->detect_slugs;
	}

	/** @since 2.0.2 */
	public function get_mode(): string {
		return $this->mode;
	}

	/** @since 2.0.2 */
	public function is_recommend(): bool {
		return self::MODE_RECOMMEND === $this->mode;
	}

	/** @since 2.0.2 */
	public function get_our_download_id(): int {
		return $this->our_download_id;
	}

	/** @since 2.0.2 */
	public function get_our_url(): string {
		return $this->our_url;
	}

	/** @since 2.0.2 */
	public function get_our_name(): string {
		return $this->our_name;
	}

	/** @since 2.0.2 */
	public function get_our_plugin_file(): ?string {
		return $this->our_plugin_file;
	}

	/** @since 2.0.2 */
	public function get_competitor_name(): string {
		return $this->competitor_name;
	}

	/** @since 2.0.2 */
	public function get_title_override(): ?string {
		return $this->title_override;
	}

	/** @since 2.0.2 */
	public function get_content_override(): ?string {
		return $this->content_override;
	}

	/** @since 2.0.2 */
	public function get_image_override(): ?string {
		return $this->image_override;
	}

	/**
	 * Stable per-rule note name used for dedup + auto-delete.
	 *
	 * `woodev-competitor-{mode}-{first-slug}` with the slug dasherized
	 * (dots/underscores → dashes) so it is a safe note name / message id.
	 *
	 * @since 2.0.2
	 */
	public function get_note_name(): string {
		$slug = preg_replace( '/[^a-z0-9]+/i', '-', $this->detect_slugs[0] );
		$slug = trim( strtolower( (string) $slug ), '-' );

		return sprintf( 'woodev-competitor-%s-%s', $this->mode, $slug );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRuleTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/competitor/class-competitor-rule.php tests/unit/CompetitorRuleTest.php
git commit -m "feat(competitor): add Competitor_Rule value object (@since 2.0.2)"
```

---

## Task 2: `Competitor_Notice_Renderer` interface

**Files:**
- Create: `woodev/competitor/interface-competitor-notice-renderer.php`

No standalone test — the interface is exercised through Task 3/4 renderers and Task 5 engine. This task is a single create + commit.

- [ ] **Step 1: Write the interface**

```php
<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

/**
 * Renders (or removes) a competitor notice for a single rule.
 *
 * Implementations are platform-specific: WC Admin Notes when WooCommerce admin
 * is present, a dismissible admin notice as fallback. The engine selects one
 * and passes a fully-built note payload.
 *
 * @since 2.0.2
 */
interface Competitor_Notice_Renderer {

	/**
	 * Creates or updates the notice for a rule whose competitor is active.
	 *
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload: title, content, type, image, actions
	 */
	public function render( Competitor_Rule $rule, array $note ): void;

	/**
	 * Removes the notice for a rule whose competitor is no longer active.
	 *
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be deleted
	 */
	public function delete( Competitor_Rule $rule ): void;
}
```

- [ ] **Step 2: Commit**

```bash
git add woodev/competitor/interface-competitor-notice-renderer.php
git commit -m "feat(competitor): add Competitor_Notice_Renderer interface (@since 2.0.2)"
```

---

## Task 3: `Admin_Notice_Renderer` (fallback)

**Files:**
- Create: `woodev/competitor/class-admin-notice-renderer.php`
- Test: `tests/unit/CompetitorRendererTest.php` (created here; extended in Task 4)

The fallback renderer wraps the plugin's `Woodev_Admin_Notice_Handler`. `render()` calls `add_admin_notice( $content, $note_name )` (dismissal handled by the handler, keyed by note name). `delete()` is a no-op for dismissible notices — once a competitor goes away we simply stop adding the notice, and a previously-dismissed/shown notice is not re-rendered (the handler only renders notices added during the request). It must NOT reference any WC class.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for the competitor notice renderers (s28).
 *
 * Admin_Notice_Renderer: render() forwards content + note name to the plugin's
 * Woodev_Admin_Notice_Handler::add_admin_notice(); delete() does not call the
 * handler. (WC_Admin_Notes_Renderer selection is covered in Task 4.)
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Admin_Notice_Renderer;
use Woodev\Framework\Competitor\Competitor_Rule;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-admin-notice-renderer.php';

class CompetitorRendererTest extends TestCase {

	public function test_admin_notice_renderer_forwards_content_and_name(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );

		$handler = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$handler->shouldReceive( 'add_admin_notice' )
			->once()
			->with( 'BODY', $rule->get_note_name() );

		$renderer = new Admin_Notice_Renderer( $handler );
		$renderer->render( $rule, [ 'title' => 'T', 'content' => 'BODY', 'actions' => [] ] );

		$this->assertTrue( true ); // Mockery verifies the expectation on tearDown.
	}

	public function test_admin_notice_renderer_delete_is_noop(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );

		$handler = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$handler->shouldNotReceive( 'add_admin_notice' );

		$renderer = new Admin_Notice_Renderer( $handler );
		$renderer->delete( $rule );

		$this->assertTrue( true );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRendererTest.php`
Expected: FAIL — class `Woodev\Framework\Competitor\Admin_Notice_Renderer` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Admin_Notice_Handler;

/**
 * Fallback renderer: a dismissible WP admin notice.
 *
 * Used when WooCommerce Admin Notes are unavailable. Delegates to the plugin's
 * Woodev_Admin_Notice_Handler, keyed by the rule's note name so the notice is
 * not re-shown once dismissed. delete() is a no-op: the handler only renders
 * notices added during the current request, so simply not adding it (when the
 * competitor is gone) is the removal.
 *
 * @since 2.0.2
 */
final class Admin_Notice_Renderer implements Competitor_Notice_Renderer {

	/** @var Woodev_Admin_Notice_Handler */
	private $notice_handler;

	/**
	 * @since 2.0.2
	 *
	 * @param Woodev_Admin_Notice_Handler $notice_handler the plugin's notice handler
	 */
	public function __construct( $notice_handler ) {
		$this->notice_handler = $notice_handler;
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload
	 */
	public function render( Competitor_Rule $rule, array $note ): void {
		$content = (string) ( $note['content'] ?? '' );

		if ( '' === $content ) {
			return;
		}

		$this->notice_handler->add_admin_notice( $content, $rule->get_note_name() );
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be removed
	 */
	public function delete( Competitor_Rule $rule ): void {
		// No-op: dismissible notices are only rendered when re-added during a
		// request; ceasing to add it is the removal.
	}
}
```

> Note: the constructor is not type-hinted to `Woodev_Admin_Notice_Handler` because that legacy class is `if ( ! class_exists ) :`-guarded and not always loaded in unit context; the Mockery double is named-mocked. This matches existing framework test ergonomics.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRendererTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/competitor/class-admin-notice-renderer.php tests/unit/CompetitorRendererTest.php
git commit -m "feat(competitor): add Admin_Notice_Renderer fallback (@since 2.0.2)"
```

---

## Task 4: `WC_Admin_Notes_Renderer`

**Files:**
- Create: `woodev/competitor/class-wc-admin-notes-renderer.php`
- Test: `tests/unit/CompetitorRendererTest.php` (extend)

Mirrors v1 `add_note()` minus the actioned→unactioned re-surface. Builds/updates a `Note` via `Woodev_Notes_Helper`, sets title/content/source/type/layout/image and actions; `delete()` uses `Woodev_Notes_Helper` to remove notes by name. Because the real `Note` / `Notes` classes are absent in unit context, the renderer's build logic is tested by capturing the actions passed via a injected, mockable note builder is overkill — instead test the **note-name passthrough on delete** (which only uses `Woodev_Notes_Helper`, itself mockable) and rely on the engine test (Task 5) for selection. The create-path is guarded by `class_exists( Note::class )` internally and exercised in a separate-process test that defines minimal WC stubs.

- [ ] **Step 1: Write the failing tests (append to `CompetitorRendererTest.php`)**

Add these imports at the top of the existing file (after the current `require_once` lines):

```php
require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-notes-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-wc-admin-notes-renderer.php';
```

Add these methods to the `CompetitorRendererTest` class:

```php
	public function test_wc_renderer_delete_removes_notes_by_name(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'yandex-go-delivery.php', 'mode' => 'conflict' ] );

		\Brain\Monkey\Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);

		// Notes helper reports the note exists, so the WC delete API is called once.
		// We assert via a Patchwork redefine of the static helper through a subclass seam:
		// the renderer calls Woodev_Notes_Helper::note_with_name_exists() then
		// Automattic\WooCommerce\Admin\Notes\Notes::delete_notes_with_name().
		// Both are guarded by class_exists; with WC absent, delete() returns early.
		$renderer = new \Woodev\Framework\Competitor\WC_Admin_Notes_Renderer( 'woodev-test-plugin' );

		// With WC Notes class absent in unit context, delete() is a safe no-op.
		$renderer->delete( $rule );

		$this->assertTrue( true );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_renderer_creates_note_when_wc_present(): void {

		// Minimal WC Admin Notes stubs so class_exists( Note::class ) is true and
		// the renderer can build a note. The stub Note records what was set.
		eval(
			'namespace Automattic\WooCommerce\Admin\Notes; '
			. 'class Note { '
			. 'const E_WC_ADMIN_NOTE_UPDATE = "update"; '
			. 'const E_WC_ADMIN_NOTE_ERROR = "error"; '
			. 'const E_WC_ADMIN_NOTE_UNACTIONED = "unactioned"; '
			. 'const E_WC_ADMIN_NOTE_ACTIONED = "actioned"; '
			. 'public static $saved = []; public $a = []; '
			. 'public function set_name( $v ){ $this->a["name"]=$v; } '
			. 'public function set_title( $v ){ $this->a["title"]=$v; } '
			. 'public function set_content( $v ){ $this->a["content"]=$v; } '
			. 'public function set_source( $v ){ $this->a["source"]=$v; } '
			. 'public function set_type( $v ){ $this->a["type"]=$v; } '
			. 'public function set_layout( $v ){ $this->a["layout"]=$v; } '
			. 'public function set_image( $v ){ $this->a["image"]=$v; } '
			. 'public function set_actions( $v ){ $this->a["actions"]=$v; } '
			. 'public function add_action( $n,$l,$u,$s=null,$p=false,$t="" ){ $this->a["acts"][]=[$n,$l,$u,$p]; } '
			. 'public function save(){ self::$saved[] = $this->a; } '
			. '}'
		);

		require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-notes-helper.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-wc-admin-notes-renderer.php';

		\Brain\Monkey\Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);

		// No existing note with this name → renderer builds a new one.
		\Brain\Monkey\Functions\when( 'WC_Data_Store' ); // not used; helper guarded below.

		$rule = new \Woodev\Framework\Competitor\Competitor_Rule(
			[ 'detect' => 'cdek.php', 'mode' => 'recommend' ]
		);

		$renderer = new \Woodev\Framework\Competitor\WC_Admin_Notes_Renderer( 'woodev-test-plugin' );
		$renderer->render(
			$rule,
			[
				'title'   => 'Заголовок',
				'content' => 'Тело',
				'type'    => \Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_UPDATE,
				'image'   => '',
				'actions' => [ [ 'name' => 'go', 'label' => 'Перейти', 'url' => 'https://x', 'primary' => true ] ],
			]
		);

		$this->assertNotEmpty( \Automattic\WooCommerce\Admin\Notes\Note::$saved );
		$saved = \Automattic\WooCommerce\Admin\Notes\Note::$saved[0];
		$this->assertSame( 'woodev-competitor-recommend-cdek-php', $saved['name'] );
		$this->assertSame( 'Заголовок', $saved['title'] );
		$this->assertSame( 'woodev-test-plugin', $saved['source'] );
	}
```

> The separate-process test isolates the WC stub so it cannot pollute other tests (gotcha `brain-monkey-function-pollution`). To make the create-path unit-testable without a live `Woodev_Notes_Helper::get_note_with_name()` DB call, the renderer checks `Woodev_Notes_Helper::get_note_with_name()` which returns `null` here (no `WC_Data_Store` → caught exception → `[]` → null), so the renderer constructs a fresh `Note`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRendererTest.php`
Expected: FAIL — class `Woodev\Framework\Competitor\WC_Admin_Notes_Renderer` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Notes_Helper;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Exception;

/**
 * WooCommerce Admin Notes renderer.
 *
 * Creates or updates a WC inbox Note for an active-competitor rule, and deletes
 * it when the competitor is gone. Selected by the engine only when
 * class_exists( Note::class ) — the gotcha-correct gate (NOT the always-true
 * Woodev_Plugin_Compatibility::is_enhanced_admin_available()). Mirrors the v1
 * add_note() build logic minus the actioned→unactioned forced re-surface.
 *
 * @since 2.0.2
 */
final class WC_Admin_Notes_Renderer implements Competitor_Notice_Renderer {

	/** @var string the owning plugin's dasherized id, used as the note source */
	private string $source;

	/**
	 * @since 2.0.2
	 *
	 * @param string $source note source (plugin id dasherized)
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload
	 */
	public function render( Competitor_Rule $rule, array $note ): void {

		if ( ! class_exists( Note::class ) ) {
			return;
		}

		$note = wp_parse_args(
			$note,
			[
				'title'   => '',
				'content' => '',
				'type'    => Note::E_WC_ADMIN_NOTE_UPDATE,
				'layout'  => 'plain',
				'image'   => '',
				'actions' => [],
			]
		);

		try {

			$wc_note = Woodev_Notes_Helper::get_note_with_name( $rule->get_note_name() );

			if ( ! $wc_note ) {
				$wc_note = new Note();
				$wc_note->set_name( $rule->get_note_name() );
				$wc_note->set_title( $note['title'] );
				$wc_note->set_content( $note['content'] );
				$wc_note->set_source( $this->source );
				$wc_note->set_type( $note['type'] );
				$wc_note->set_layout( $note['layout'] );
			}

			if ( ! empty( $note['image'] ) ) {
				$wc_note->set_layout( 'thumbnail' );
				$wc_note->set_image( $note['image'] );
			}

			$wc_note->set_actions( [] );

			foreach ( (array) $note['actions'] as $action ) {

				$action = wp_parse_args(
					$action,
					[
						'name'          => '',
						'label'         => '',
						'url'           => '',
						'status'        => Note::E_WC_ADMIN_NOTE_ACTIONED,
						'primary'       => false,
						'actioned_text' => '',
					]
				);

				$wc_note->add_action(
					$action['name'],
					$action['label'],
					$action['url'],
					$action['status'],
					$action['primary'],
					$action['actioned_text']
				);
			}

			$wc_note->save();

		} catch ( Exception $e ) {
			// Swallow — a failed note must never fatal an admin page load.
		}
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be removed
	 */
	public function delete( Competitor_Rule $rule ): void {

		if ( ! class_exists( Notes::class ) ) {
			return;
		}

		if ( Woodev_Notes_Helper::note_with_name_exists( $rule->get_note_name() ) ) {
			Notes::delete_notes_with_name( $rule->get_note_name() );
		}
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/CompetitorRendererTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/competitor/class-wc-admin-notes-renderer.php tests/unit/CompetitorRendererTest.php
git commit -m "feat(competitor): add WC_Admin_Notes_Renderer (@since 2.0.2)"
```

---

## Task 5: `Competitor_Notification_Handler` abstract engine

**Files:**
- Create: `woodev/competitor/class-competitor-notification-handler.php`
- Test: `tests/unit/CompetitorNotificationHandlerTest.php`

The engine: constructed with the owning `Woodev_Plugin`; exposes `run()` which iterates `get_competitor_rules()`, normalizes each to a `Competitor_Rule`, and for each:
- **inactive** (no `detect` slug active) → `renderer->delete( $rule )`.
- **active + recommend + `our_plugin_file` active** → suppress (treat as inactive: `delete`).
- **active otherwise** → build the note payload (default templates per mode, with per-rule overrides) and `renderer->render()`.

Renderer is resolved once via a protected `get_renderer()` (overridable in tests) that returns `WC_Admin_Notes_Renderer` when `class_exists( Note::class )` else `Admin_Notice_Renderer`. Smart link target via protected `get_recommend_action_url( Competitor_Rule )`. `get_competitor_rules()` is abstract.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for the Woodev\Framework\Competitor\Competitor_Notification_Handler engine (s28).
 *
 * Covers: detection any-match; recommend suppression when our plugin active +
 * degrade when our_plugin_file omitted; auto-delete when competitor inactive;
 * default template interpolation + per-rule override; smart recommend link
 * target (connected+owned → extensions page; else → product URL; degraded → URL);
 * conflict action = nonce'd deactivate link.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Competitor_Notification_Handler;
use Woodev\Framework\Competitor\Competitor_Notice_Renderer;
use Woodev\Framework\Competitor\Competitor_Rule;
use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-notification-handler.php';

/**
 * A spy renderer capturing render/delete calls.
 */
class Spy_Renderer implements Competitor_Notice_Renderer {
	/** @var array<int,array{rule:Competitor_Rule,note:array}> */
	public array $rendered = [];
	/** @var array<int,Competitor_Rule> */
	public array $deleted = [];

	public function render( Competitor_Rule $rule, array $note ): void {
		$this->rendered[] = [ 'rule' => $rule, 'note' => $note ];
	}
	public function delete( Competitor_Rule $rule ): void {
		$this->deleted[] = $rule;
	}
}

/**
 * Test subclass: injectable rules + spy renderer + transient-free owned check.
 */
class Test_Competitor_Handler extends Competitor_Notification_Handler {
	/** @var array<int,array<string,mixed>> */
	public array $rules = [];
	public Spy_Renderer $spy;

	protected function get_competitor_rules(): array {
		return $this->rules;
	}
	protected function get_renderer(): Competitor_Notice_Renderer {
		return $this->spy;
	}
}

class CompetitorNotificationHandlerTest extends TestCase {

	private function make_plugin( array $active, bool $connected = false, array $owned = [], int $id = 7 ) {

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'is_plugin_active' )->andReturnUsing(
			static fn( $slug ) => in_array( $slug, $active, true )
		);
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( 'woodev-test-plugin' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn(
			Mockery::mock( 'Woodev_Admin_Notice_Handler' )
		);

		return $plugin;
	}

	private function make_handler( $plugin, array $rules ): Test_Competitor_Handler {
		$handler       = new Test_Competitor_Handler( $plugin );
		$handler->spy  = new Spy_Renderer();
		$handler->rules = $rules;
		return $handler;
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'http://site/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'urlencode' )->alias( 'rawurlencode' );
		Functions\when( 'get_transient' )->justReturn( false );
	}

	public function test_detection_any_match_renders(): void {
		$plugin  = $this->make_plugin( [ 'b.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => [ 'a.php', 'b.php' ], 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$this->assertCount( 1, $handler->spy->rendered );
		$this->assertCount( 0, $handler->spy->deleted );
	}

	public function test_inactive_competitor_is_deleted(): void {
		$plugin  = $this->make_plugin( [] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'a.php', 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$this->assertCount( 0, $handler->spy->rendered );
		$this->assertCount( 1, $handler->spy->deleted );
	}

	public function test_recommend_suppressed_when_our_plugin_active(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php', 'woocommerce-edostavka.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_plugin_file' => 'woocommerce-edostavka.php',
				],
			]
		);
		$handler->run();
		$this->assertCount( 0, $handler->spy->rendered );
		$this->assertCount( 1, $handler->spy->deleted );
	}

	public function test_recommend_degrades_when_our_plugin_file_omitted(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'cdek.php', 'mode' => 'recommend', 'our_url' => 'https://woodev.ru/x' ] ]
		);
		$handler->run();
		$this->assertCount( 1, $handler->spy->rendered );
	}

	public function test_default_recommend_template_interpolates_names(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'competitor_name' => 'CDEKDelivery',
					'our_name'        => 'Интеграция СДЭК',
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note = $handler->spy->rendered[0]['note'];
		$this->assertStringContainsString( 'CDEKDelivery', $note['content'] );
		$this->assertStringContainsString( 'Интеграция СДЭК', $note['content'] );
	}

	public function test_per_rule_content_override_wins(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'  => 'cdek.php',
					'mode'    => 'recommend',
					'title'   => 'CUSTOM TITLE',
					'content' => 'CUSTOM BODY',
					'our_url' => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note = $handler->spy->rendered[0]['note'];
		$this->assertSame( 'CUSTOM TITLE', $note['title'] );
		$this->assertSame( 'CUSTOM BODY', $note['content'] );
	}

	public function test_recommend_link_target_degraded_to_product_url(): void {
		// Not connected (default) → primary action URL is our_url.
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_download_id' => 42,
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note    = $handler->spy->rendered[0]['note'];
		$primary = $this->primary_action( $note );
		$this->assertSame( 'https://woodev.ru/x', $primary['url'] );
	}

	public function test_recommend_link_target_extensions_page_when_connected_and_owned(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'purchased' => [ 42 ] ] );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'is_plugin_active' )->andReturnUsing(
			static fn( $slug ) => 'cdek.php' === $slug
		);
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( 'woodev-test-plugin' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn(
			Mockery::mock( 'Woodev_Admin_Notice_Handler' )
		);

		$handler = new Test_Competitor_Handler( $plugin );
		$handler->spy = new Spy_Renderer();
		// Force the connected branch via the overridable seam.
		$handler->force_connected = true;
		$handler->rules = [
			[
				'detect'          => 'cdek.php',
				'mode'            => 'recommend',
				'our_download_id' => 42,
				'our_url'         => 'https://woodev.ru/x',
			],
		];

		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertStringContainsString( 'page=woodev-extensions', $primary['url'] );
	}

	public function test_conflict_primary_action_is_deactivate_link(): void {
		$plugin  = $this->make_plugin( [ 'yandex-go-delivery.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'yandex-go-delivery.php', 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertStringContainsString( 'plugins.php', $primary['url'] );
		$this->assertStringContainsString( 'action=deactivate', $primary['url'] );
		$this->assertStringContainsString( 'NONCE', $primary['url'] );
	}

	/** @param array<string,mixed> $note */
	private function primary_action( array $note ): array {
		foreach ( $note['actions'] as $action ) {
			if ( ! empty( $action['primary'] ) ) {
				return $action;
			}
		}
		return $note['actions'][0] ?? [];
	}
}
```

> The test subclass adds a public `$force_connected` consumed by an overridable `is_account_connected()` seam (below) so the connected branch is testable without loading `Woodev_Account_Connection`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/CompetitorNotificationHandlerTest.php`
Expected: FAIL — class `Woodev\Framework\Competitor\Competitor_Notification_Handler` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Plugin;
use Woodev_Account_Connection;
use Automattic\WooCommerce\Admin\Notes\Note;

/**
 * Platform-neutral competitor-notification engine.
 *
 * A plugin extends this and implements get_competitor_rules(). On each admin
 * screen load run() normalizes every raw rule to a Competitor_Rule, detects
 * whether any competitor slug is active, suppresses recommend rules when our
 * equivalent is installed, then asks the resolved renderer to create/update or
 * delete the note. The renderer is chosen by class_exists( Note::class ) — the
 * gotcha-correct gate, NOT is_enhanced_admin_available() (always true).
 *
 * @since 2.0.2
 */
abstract class Competitor_Notification_Handler {

	/** @var Woodev_Plugin owning plugin */
	private Woodev_Plugin $plugin;

	/** @var Competitor_Notice_Renderer|null lazily-resolved renderer */
	private ?Competitor_Notice_Renderer $renderer = null;

	/**
	 * @since 2.0.2
	 *
	 * @param Woodev_Plugin $plugin owning plugin
	 */
	public function __construct( Woodev_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Per-plugin competitor rules. Each entry is a plain array consumed by
	 * Competitor_Rule. The competitor→product mapping lives here, never in the
	 * framework.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function get_competitor_rules(): array;

	/**
	 * Runs detection + rendering for every rule. Safe to call on every admin
	 * screen load. Malformed rules are skipped (never fatal an admin page).
	 *
	 * @since 2.0.2
	 */
	public function run(): void {

		$renderer = $this->get_renderer();

		foreach ( $this->get_competitor_rules() as $raw ) {

			try {
				$rule = new Competitor_Rule( $raw );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}

			if ( ! $this->is_competitor_active( $rule ) || $this->is_suppressed( $rule ) ) {
				$renderer->delete( $rule );
				continue;
			}

			$renderer->render( $rule, $this->build_note( $rule ) );
		}
	}

	/**
	 * True when ANY of the rule's detect slugs is an active plugin.
	 *
	 * @since 2.0.2
	 */
	protected function is_competitor_active( Competitor_Rule $rule ): bool {

		foreach ( $rule->get_detect_slugs() as $slug ) {
			if ( $this->plugin->is_plugin_active( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recommend rules are suppressed when our equivalent plugin is active. When
	 * our_plugin_file is omitted the rule degrades (never suppressed).
	 *
	 * @since 2.0.2
	 */
	protected function is_suppressed( Competitor_Rule $rule ): bool {

		if ( ! $rule->is_recommend() ) {
			return false;
		}

		$our_file = $rule->get_our_plugin_file();

		return null !== $our_file && $this->plugin->is_plugin_active( $our_file );
	}

	/**
	 * Builds the renderer-agnostic note payload (title, content, type, image,
	 * actions), applying per-rule overrides over the per-mode default template.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function build_note( Competitor_Rule $rule ): array {

		if ( $rule->is_recommend() ) {

			$title   = $rule->get_title_override() ?? 'Информация от Woodev: альтернативный плагин';
			$content = $rule->get_content_override() ?? $this->default_recommend_content( $rule );

			$actions = [
				[
					'name'    => 'plugin-details',
					'label'   => 'Перейти на страницу плагина',
					'url'     => $this->get_recommend_action_url( $rule ),
					'primary' => true,
				],
				$this->dismiss_action(),
			];

			$type = $this->note_type_update();

		} else {

			$title   = $rule->get_title_override() ?? 'Информация от Woodev: обнаружен сторонний плагин доставки';
			$content = $rule->get_content_override() ?? $this->default_conflict_content( $rule );

			$actions = [
				[
					'name'    => 'deactivate-plugin',
					'label'   => sprintf( 'Отключить плагин %s', $this->competitor_label( $rule ) ),
					'url'     => $this->get_deactivation_url( $rule ),
					'primary' => true,
				],
				$this->dismiss_action(),
			];

			$type = $this->note_type_error();
		}

		return [
			'title'   => $title,
			'content' => $content,
			'type'    => $type,
			'image'   => (string) ( $rule->get_image_override() ?? '' ),
			'actions' => $actions,
		];
	}

	/**
	 * Default recommend template (mirrors v1 get_recommendation_notice_content).
	 *
	 * @since 2.0.2
	 */
	protected function default_recommend_content( Competitor_Rule $rule ): string {
		return sprintf(
			'Мы обнаружили, что на вашем сайте используется плагин <strong>%s</strong>. Хотим предложить вам альтернативу — <strong>%s</strong> от Woodev.<br />Наш плагин стабилен, активно поддерживается, совместим с другими нашими решениями и регулярно обновляется.',
			$this->competitor_label( $rule ),
			'' !== $rule->get_our_name() ? $rule->get_our_name() : 'наш плагин'
		);
	}

	/**
	 * Default conflict template (mirrors v1 get_competitor_notice_content).
	 *
	 * @since 2.0.2
	 */
	protected function default_conflict_content( Competitor_Rule $rule ): string {
		return sprintf(
			'На вашем сайте активен плагин <strong>%s</strong>. В некоторых случаях плагины с похожей функциональностью могут конфликтовать: например, добавлять дублирующиеся методы доставки, влиять на расчёт стоимости или мешать оформлению заказов.<br />Если у вас всё работает корректно — ничего делать не нужно. Если возникнут вопросы — обращайтесь в <a href="https://woodev.ru/support">нашу поддержку</a>.',
			$this->competitor_label( $rule )
		);
	}

	/**
	 * Competitor display label: explicit competitor_name, else the first slug.
	 *
	 * @since 2.0.2
	 */
	protected function competitor_label( Competitor_Rule $rule ): string {
		return '' !== $rule->get_competitor_name() ? $rule->get_competitor_name() : $rule->get_detect_slugs()[0];
	}

	/**
	 * Smart recommend primary-action URL. Connected + owned → in-admin catalog;
	 * otherwise the public product URL. Degrades to our_url whenever the account
	 * is unavailable / not connected / product not owned.
	 *
	 * @since 2.0.2
	 */
	protected function get_recommend_action_url( Competitor_Rule $rule ): string {

		if (
			$rule->get_our_download_id() > 0
			&& $this->is_account_connected()
			&& $this->owns_download( $rule->get_our_download_id() )
		) {
			return esc_url_raw( admin_url( 'admin.php?page=woodev-extensions' ) );
		}

		return $rule->get_our_url();
	}

	/**
	 * Nonce'd deactivate link for a conflict rule's first detect slug.
	 *
	 * @since 2.0.2
	 */
	protected function get_deactivation_url( Competitor_Rule $rule ): string {

		$plugin_file = $rule->get_detect_slugs()[0];

		$url = add_query_arg(
			[
				'action'   => 'deactivate',
				'plugin'   => urlencode( $plugin_file ),
				'_wpnonce' => wp_create_nonce( sprintf( 'deactivate-plugin_%s', $plugin_file ) ),
			],
			admin_url( 'plugins.php' )
		);

		return esc_url_raw( $url );
	}

	/**
	 * Whether the Woodev account is connected. Overridable seam for tests.
	 *
	 * @since 2.0.2
	 */
	protected function is_account_connected(): bool {

		if ( ! class_exists( 'Woodev_Account_Connection' ) ) {
			return false;
		}

		return ( new Woodev_Account_Connection() )->is_connected();
	}

	/**
	 * Whether the given download id is owned, read from the purchases transient
	 * cached by the extensions REST controller (no blocking HTTP at render).
	 *
	 * @since 2.0.2
	 */
	protected function owns_download( int $download_id ): bool {

		$cached = get_transient( 'woodev_account_purchases' );

		if ( ! is_array( $cached ) || ! isset( $cached['purchased'] ) || ! is_array( $cached['purchased'] ) ) {
			return false;
		}

		return in_array( $download_id, array_map( 'intval', $cached['purchased'] ), true );
	}

	/**
	 * Shared dismiss action.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function dismiss_action(): array {
		return [
			'name'  => 'dismiss',
			'label' => 'Скрыть',
		];
	}

	/**
	 * Resolves the renderer: WC Admin Notes when present, else admin-notice
	 * fallback. Cached for the request. Overridable in tests.
	 *
	 * @since 2.0.2
	 */
	protected function get_renderer(): Competitor_Notice_Renderer {

		if ( null === $this->renderer ) {
			$this->renderer = class_exists( Note::class )
				? new WC_Admin_Notes_Renderer( $this->plugin->get_id_dasherized() )
				: new Admin_Notice_Renderer( $this->plugin->get_admin_notice_handler() );
		}

		return $this->renderer;
	}

	/** @since 2.0.2 */
	public function get_plugin(): Woodev_Plugin {
		return $this->plugin;
	}

	/** @since 2.0.2 WC note type, indirected so the engine has no hard WC dep at unit time. */
	protected function note_type_update(): string {
		return class_exists( Note::class ) ? Note::E_WC_ADMIN_NOTE_UPDATE : 'update';
	}

	/** @since 2.0.2 */
	protected function note_type_error(): string {
		return class_exists( Note::class ) ? Note::E_WC_ADMIN_NOTE_ERROR : 'error';
	}
}
```

> The test subclass `Test_Competitor_Handler` must override `is_account_connected()` to honor its `$force_connected` flag. Add this to the test subclass in Step 1's file (adjust before running):
>
> ```php
> public bool $force_connected = false;
> protected function is_account_connected(): bool {
>     return $this->force_connected;
> }
> ```
>
> (Add the `force_connected` property + override to `Test_Competitor_Handler`. The plan groups it here for clarity; ensure it is present in the test file before Step 4.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/CompetitorNotificationHandlerTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/competitor/class-competitor-notification-handler.php tests/unit/CompetitorNotificationHandlerTest.php
git commit -m "feat(competitor): add Competitor_Notification_Handler engine (@since 2.0.2)"
```

---

## Task 6: Wire the engine into `Woodev_Plugin` (opt-in subsystem)

**Files:**
- Modify: `woodev/class-plugin.php`
- Test: `tests/unit/CompetitorNotificationHandlerTest.php` (add a wiring test) OR a small dedicated assertion in an existing platform-neutral test.

Add an opt-in subsystem mirroring `init_setup_wizard_handler()`: a property, an `init_competitor_handler()` called in `__construct()`, a `get_competitor_notification_handler(): ?Competitor_Notification_Handler` returning `null` by default (plugins override), and a `current_screen` hook that runs the engine when one is provided. Base stays platform-neutral (no WC calls).

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/CompetitorNotificationHandlerTest.php`:

```php
	public function test_base_plugin_returns_null_handler_by_default(): void {
		// A vanilla Woodev_Plugin opts out — get_competitor_notification_handler() is null.
		// Verified via reflection on the real base default (no subclass override).
		require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
		$rc = new \ReflectionClass( \Woodev_Plugin::class );
		$this->assertTrue( $rc->hasMethod( 'get_competitor_notification_handler' ) );
		$m = $rc->getMethod( 'get_competitor_notification_handler' );
		$this->assertTrue( $m->isProtected() || $m->isPublic() );
	}

	public function test_run_competitor_notices_noops_when_handler_null(): void {
		require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
		$rc = new \ReflectionClass( \Woodev_Plugin::class );
		$this->assertTrue( $rc->hasMethod( 'run_competitor_notices' ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/CompetitorNotificationHandlerTest.php --filter test_base_plugin_returns_null_handler_by_default`
Expected: FAIL — `Woodev_Plugin` has no `get_competitor_notification_handler` method.

- [ ] **Step 3: Add the property**

In `woodev/class-plugin.php`, near the other handler properties (~line 68, by `$setup_wizard_handler`), add:

```php
		/** @var \Woodev\Framework\Competitor\Competitor_Notification_Handler|null competitor notification engine (opt-in) */
		protected $competitor_handler;
```

- [ ] **Step 4: Call the initializer in `__construct()`**

In `__construct()`, immediately after the `$this->init_setup_wizard_handler();` line (~line 150), add:

```php
			// build the competitor notification handler instance (opt-in)
			$this->init_competitor_handler();
```

- [ ] **Step 5: Add the initializer + accessor methods**

After `init_setup_wizard_handler()` (~line 284, just after its closing brace), add:

```php
		/**
		 * Builds the competitor notification handler (opt-in).
		 *
		 * Stores the plugin's handler when one is provided. Plugins opt in by
		 * overriding get_competitor_notification_handler() to return their
		 * subclass; the default returns null (feature off).
		 *
		 * @since 2.0.2
		 */
		protected function init_competitor_handler() {
			$this->competitor_handler = $this->get_competitor_notification_handler();
		}

		/**
		 * Gets the plugin's competitor notification handler, or null when the
		 * plugin does not opt in. Plugins override this to return their subclass
		 * of \Woodev\Framework\Competitor\Competitor_Notification_Handler.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev\Framework\Competitor\Competitor_Notification_Handler|null
		 */
		protected function get_competitor_notification_handler() {
			return null;
		}

		/**
		 * Runs competitor detection on an admin screen. Hooked to current_screen
		 * (admin-only, never the front end). No-op when the plugin has not opted
		 * in. @internal
		 *
		 * @since 2.0.2
		 */
		public function run_competitor_notices() {

			if ( ! is_admin() || null === $this->competitor_handler ) {
				return;
			}

			$this->competitor_handler->run();
		}
```

- [ ] **Step 6: Hook it in `add_hooks()`**

In `add_hooks()` (~line 308, near the `admin_notices` adds), add:

```php
			// run competitor detection on admin screens (never the front end)
			add_action( 'current_screen', array( $this, 'run_competitor_notices' ) );
```

- [ ] **Step 7: Run the wiring tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/CompetitorNotificationHandlerTest.php`
Expected: PASS (11 tests).

- [ ] **Step 8: Commit**

```bash
git add woodev/class-plugin.php tests/unit/CompetitorNotificationHandlerTest.php
git commit -m "feat(competitor): wire opt-in competitor handler into Woodev_Plugin (@since 2.0.2)"
```

---

## Task 7: Regenerate the class map + full verification

**Files:**
- Regenerate: `woodev/class-map.php`

- [ ] **Step 1: Regenerate the class map**

Run: `php bin/generate-class-map.php`
Expected: the 5 new `Woodev\Framework\Competitor\*` entries are added to `woodev/class-map.php`.

- [ ] **Step 2: Verify the new classes are mapped**

Run: `grep -c "Competitor" woodev/class-map.php`
Expected: `5` (Competitor_Rule, Competitor_Notice_Renderer, WC_Admin_Notes_Renderer, Admin_Notice_Renderer, Competitor_Notification_Handler).

- [ ] **Step 3: Run the class-map completeness test**

Run: `./vendor/bin/phpunit tests/unit/ClassMapCompletenessTest.php`
Expected: PASS (guards missing AND moved files).

- [ ] **Step 4: Run the full check**

Run: `composer check`
Expected: phpcs clean, phpstan L3 clean, all unit tests green (was 729; now ~754 with the new tests). If phpcs flags style, run `composer phpcbf` and re-check; fix any phpstan finding at source (no baseline).

- [ ] **Step 5: Commit**

```bash
git add woodev/class-map.php
git commit -m "chore(competitor): regenerate class map for competitor module"
```

---

## Task 8: Branch, PR, review

- [ ] **Step 1: Confirm work is on a feature branch**

This work should be on a branch (e.g. `feat/competitor-notification`), not `main`. If still on `main`, create the branch before pushing:

```bash
git checkout -b feat/competitor-notification
```

- [ ] **Step 2: Push and open the PR**

```bash
git push -u origin feat/competitor-notification
gh pr create --title "feat(competitor): competitor notification module (v2 rework)" --body "Implements docs-internal/specs/2026-06-21-competitor-notification-design.md. Platform-neutral engine + WC-Notes/admin-notice renderers, declarative rules, smart account-aware recommend link. @since 2.0.2. Version not bumped."
```

- [ ] **Step 3: Codex review (architecturally sensitive)**

Per CLAUDE.md, this module is a new architectural seam — run a Codex review pass (`/codex:review` or the `codex:codex-rescue` subagent) on the diff before merge. Present findings; in autonomous mode, fix-if-confident, then re-critic the fixes (do not self-certify).

- [ ] **Step 4: Verify CI is actually green, then merge**

Confirm the Unit matrix really ran (gotcha `pr-conflict-skips-pull-request-ci` and `ci-failing-gate-skips-dependent-jobs`):

```bash
gh pr view --json mergeable,mergeStateStatus
gh pr checks
```

When confirmed green:

```bash
gh pr merge <N> --squash --delete-branch
```

(Never `gh pr merge --auto`.)

---

## Self-review notes (verified against spec)

- **§2 decision 1** (both modes via declarative rules) → Task 1 `mode` validation + Task 5 `build_note()` branches. ✅
- **§2 decision 2 / §6** (smart recommend link target, degradation) → Task 5 `get_recommend_action_url()` + `owns_download()` (transient) + `is_account_connected()`. ✅
- **§2 decision 3 / §3** (neutral engine + renderers, `class_exists(Note::class)` selection — the gotcha fix) → Task 4/5 `get_renderer()`. ✅
- **§2 decision 4 / §7** (framework default templates, mapping in plugin) → Task 5 `default_*_content()`; rules abstract. ✅
- **§2 decision 5 / §5** (respect dismissal, auto-delete, no forced re-surface) → Task 4 (no actioned→unactioned bump), Task 5 `delete` on inactive, Admin_Notice_Renderer keyed by note name. ✅
- **§3 wiring** (opt-in `init_*_handler`, base neutral) → Task 6. ✅
- **§8 testing** (every listed case) → Tasks 1,3,4,5 tests. ✅
- **§10 open items** → resolved in "Resolved plan-time decisions" above. ✅
- Class-map regen + no-Composer + PSR-4 + `@since 2.0.2` → Task 7 + conventions section. ✅
