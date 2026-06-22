# Setup Wizard (OB-10) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the legacy server-rendered `Woodev_Plugin_Setup_Wizard` with a neutral, React-driven, opt-in setup wizard whose steps are declared in PHP over the existing neutral Settings API.

**Architecture:** Neutral abstract `Woodev\Framework\Setup\Setup_Wizard` (zero WC) + thin `Woocommerce_Setup_Wizard`. Steps are declared in PHP (`register_step`/`register_content_step`) and reference existing `Woodev_Setting` ids; one generic React shell renders any declared step. Data flows over the neutral `woodev/v1` REST namespace. First install triggers a one-shot redirect + a notice-fallback.

**Tech Stack:** PHP 7.4–8.1 (namespaced PSR-4, short array `[]` only), WordPress REST, Brain Monkey/Mockery (unit), WP test library (integration), `@wordpress/scripts` (React, classic JSX runtime).

**Spec:** `docs-internal/specs/2026-06-22-setup-wizard-design.md`.

**Conventions for every task:** new code is authored directly in `Woodev\Framework\Setup\*` (PSR-4) with `[]` arrays only. Type declarations + docblocks (`@since 2.0.2`, `@param`, `@return`) on all methods. Commit with Conventional Commits. PHPStan runs only on Linux CI (local Windows segfault — gotcha `phpstan-windows-parallel-worker-segfault`); locally run `composer phpcs` + `composer test:unit`.

---

## File Structure

| File | Responsibility |
|---|---|
| `woodev/setup/class-step.php` (create) | `Woodev\Framework\Setup\Step` value object — one step descriptor |
| `woodev/setup/class-setup-wizard.php` (create) | `Woodev\Framework\Setup\Setup_Wizard` abstract neutral core |
| `woodev/setup/class-woocommerce-setup-wizard.php` (create) | `Woodev\Framework\Setup\Woocommerce_Setup_Wizard` thin WC subclass |
| `woodev/rest-api/controllers/class-rest-api-setup.php` (create) | `Woodev_REST_API_Setup` REST controller (woodev/v1) |
| `woodev/class-plugin.php` (modify) | opt-in `get_setup_wizard_handler()`, remove dead `init_setup_wizard_handler()`, wire handler |
| `woodev/admin/abstract-plugin-admin-setup-wizard.php` (delete) | legacy wizard removed |
| `src/setup-wizard/*` (create) | React shell (index, App, StepView, ControlField, Progress, rest client) |
| `woodev/assets/build/setup-wizard/*` (build output) | built bundle (LF EOL) |
| `composer.json` (modify) | add `woodev/setup` to classmap (dev/test) |
| `woodev/class-map.php` (regenerate) | runtime autoload map |
| `tests/unit/SetupWizardStepTest.php` etc. (create) | unit tests |
| `tests/unit/PlatformNeutralSetupWizardTest.php` (rewrite) | neutral wizard test |
| `tests/_fixtures/*/...` (modify ×4) | drop `init_setup_wizard_handler(){}` stubs |
| `tests/integration/SetupWizardRestTest.php` (create) | REST integration test |

---

## Task 0: Branch setup

- [ ] **Step 1: Create the feature branch**

We are on `main`. All implementation goes on a branch → PR → green CI → squash-merge (never `--auto`). This creates an isolated branch in the current working tree (no separate folder).

Run:
```bash
git switch -c feature/setup-wizard-ob10
git status
```
Expected: on branch `feature/setup-wizard-ob10`, clean (the spec + convention commit `eab21c4` is already on `main`).

---

## Task 1: `Step` value object

**Files:**
- Create: `woodev/setup/class-step.php`
- Test: `tests/unit/SetupWizardStepTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';

class SetupWizardStepTest extends TestCase {

	public function test_settings_step_exposes_setting_ids(): void {
		$step = Step::settings( 'connection', 'Подключение', [ 'api_key', 'api_secret' ] );

		$this->assertSame( 'connection', $step->get_id() );
		$this->assertSame( 'Подключение', $step->get_label() );
		$this->assertSame( Step::TYPE_SETTINGS, $step->get_type() );
		$this->assertSame( [ 'api_key', 'api_secret' ], $step->get_setting_ids() );
		$this->assertNull( $step->get_on_save() );
		$this->assertTrue( $step->is_visible() );
	}

	public function test_content_step_holds_a_callable_and_no_setting_ids(): void {
		$cb   = static function (): string { return '<p>hi</p>'; };
		$step = Step::content( 'welcome', 'Добро пожаловать', $cb );

		$this->assertSame( Step::TYPE_CONTENT, $step->get_type() );
		$this->assertSame( [], $step->get_setting_ids() );
		$this->assertSame( $cb, $step->get_content() );
	}

	public function test_optional_on_save_and_visibility_callback(): void {
		$save = static function (): void {};
		$step = Step::settings( 'delivery', 'Доставка', [ 'tariff' ], $save )
			->set_visibility_callback( static function (): bool { return false; } );

		$this->assertSame( $save, $step->get_on_save() );
		$this->assertFalse( $step->is_visible() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardStepTest.php`
Expected: FAIL — class `Woodev\Framework\Setup\Step` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Setup wizard step descriptor.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * One declarative setup-wizard step.
 *
 * @since 2.0.2
 */
final class Step {

	/** @var string a step that renders fields from the Settings API. */
	const TYPE_SETTINGS = 'settings';

	/** @var string a step that renders arbitrary content / an action. */
	const TYPE_CONTENT = 'content';

	/** @var string step id. */
	private string $id;

	/** @var string step label. */
	private string $label;

	/** @var string step type. */
	private string $type;

	/** @var string[] referenced Woodev_Setting ids (settings steps). */
	private array $setting_ids;

	/** @var callable|string|null content callback / markup (content steps). */
	private $content;

	/** @var callable|null server-side save side-effect. */
	private $on_save;

	/** @var callable|null visibility predicate. */
	private $visibility_callback;

	/**
	 * Use the named constructors instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, string $type ) {
		$this->id          = $id;
		$this->label       = $label;
		$this->type        = $type;
		$this->setting_ids = [];
		$this->content     = null;
		$this->on_save     = null;

		$this->visibility_callback = null;
	}

	/**
	 * Builds a settings step.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $id          step id.
	 * @param string        $label       step label.
	 * @param string[]      $setting_ids referenced setting ids.
	 * @param callable|null $on_save     optional save side-effect.
	 * @return self
	 */
	public static function settings( string $id, string $label, array $setting_ids, ?callable $on_save = null ): self {
		$step              = new self( $id, $label, self::TYPE_SETTINGS );
		$step->setting_ids = array_values( $setting_ids );
		$step->on_save     = $on_save;

		return $step;
	}

	/**
	 * Builds a content step.
	 *
	 * @since 2.0.2
	 *
	 * @param string          $id      step id.
	 * @param string          $label   step label.
	 * @param callable|string $content content callback or markup.
	 * @return self
	 */
	public static function content( string $id, string $label, $content ): self {
		$step          = new self( $id, $label, self::TYPE_CONTENT );
		$step->content = $content;

		return $step;
	}

	/**
	 * Sets the visibility predicate (fluent).
	 *
	 * @since 2.0.2
	 *
	 * @param callable $callback predicate returning bool.
	 * @return self
	 */
	public function set_visibility_callback( callable $callback ): self {
		$this->visibility_callback = $callback;

		return $this;
	}

	/** @since 2.0.2 @return string */
	public function get_id(): string {
		return $this->id;
	}

	/** @since 2.0.2 @return string */
	public function get_label(): string {
		return $this->label;
	}

	/** @since 2.0.2 @return string */
	public function get_type(): string {
		return $this->type;
	}

	/** @since 2.0.2 @return string[] */
	public function get_setting_ids(): array {
		return $this->setting_ids;
	}

	/** @since 2.0.2 @return callable|string|null */
	public function get_content() {
		return $this->content;
	}

	/** @since 2.0.2 @return callable|null */
	public function get_on_save(): ?callable {
		return $this->on_save;
	}

	/**
	 * Whether this step is currently visible.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		if ( null === $this->visibility_callback ) {
			return true;
		}

		return (bool) call_user_func( $this->visibility_callback );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardStepTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-step.php tests/unit/SetupWizardStepTest.php
git commit -m "feat(setup): add Step value object for the setup wizard"
```

---

## Task 2: `Setup_Wizard` — step registry & neutrality

**Files:**
- Create: `woodev/setup/class-setup-wizard.php`
- Test: `tests/unit/SetupWizardRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;
use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/** Minimal concrete wizard for registry tests (no plugin construction). */
class Registry_Test_Wizard extends Setup_Wizard {
	public array $declared = [];
	public function __construct() {}                       // bypass parent wiring
	public function get_id(): string { return 'reg'; }     // no plugin in this double
	protected function register_steps(): void {
		foreach ( $this->declared as $id => $kind ) {       // associative: key=id, value=kind
			if ( 'content' === $kind ) {
				$this->register_content_step( $id, $id, static function (): string { return ''; } );
			} else {
				$this->register_step( $id, $id, [ $id . '_field' ] );
			}
		}
	}
	public function expose_build_steps(): void { $this->build_steps(); } // test hook
}

class SetupWizardRegistryTest extends TestCase {

	public function test_registers_and_orders_steps(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 ); // return $steps unchanged
		$wizard = new Registry_Test_Wizard();
		$wizard->declared = [ 'welcome' => 'content', 'connection' => 'settings' ];
		$wizard->expose_build_steps();

		$this->assertTrue( $wizard->has_steps() );
		$this->assertSame( [ 'welcome', 'connection' ], array_keys( $wizard->get_steps() ) );
		$this->assertInstanceOf( Step::class, $wizard->get_steps()['connection'] );
	}

	public function test_capability_default_is_neutral(): void {
		$wizard = new Registry_Test_Wizard();
		$this->assertSame( 'manage_options', $wizard->get_required_capability() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardRegistryTest.php`
Expected: FAIL — class `Setup_Wizard` not found.

- [ ] **Step 3: Write minimal implementation**

Create `woodev/setup/class-setup-wizard.php` with the registry + neutrality slice (later tasks append state/trigger/page methods to this same class):

```php
<?php
/**
 * Neutral setup wizard core.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Platform-neutral, opt-in, React-driven setup wizard.
 *
 * Plugins extend this (or Woocommerce_Setup_Wizard), implement register_steps(),
 * and return an instance from Woodev_Plugin::get_setup_wizard_handler().
 *
 * @since 2.0.2
 */
abstract class Setup_Wizard {

	/** @var \Woodev_Plugin owning plugin. */
	protected $plugin;

	/** @var string required capability (neutral default). */
	protected string $required_capability = 'manage_options';

	/** @var Step[] registered steps keyed by id (visible only, after build). */
	protected array $steps = [];

	/**
	 * Constructs the wizard and wires its hooks.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Plugin $plugin owning plugin.
	 */
	public function __construct( \Woodev_Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->build_steps();

		if ( $this->has_steps() ) {
			$this->add_hooks();
		}
	}

	/**
	 * Registers the wizard's steps. Plugins implement this.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	abstract protected function register_steps(): void;

	/**
	 * Builds and filters the step list (visible steps only).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function build_steps(): void {
		$this->steps = [];
		$this->register_steps();

		/**
		 * Filters the registered setup-wizard steps.
		 *
		 * @since 2.0.2
		 *
		 * @param Step[]       $steps    registered steps keyed by id.
		 * @param Setup_Wizard $instance wizard instance.
		 */
		$steps = apply_filters( "woodev_{$this->get_id()}_setup_wizard_steps", $this->steps, $this );

		$this->steps = array_filter(
			$steps,
			static function ( $step ): bool {
				return $step instanceof Step && $step->is_visible();
			}
		);
	}

	/**
	 * Registers a settings step (fields resolved from the plugin's Settings API).
	 *
	 * @since 2.0.2
	 *
	 * @param string        $id          step id.
	 * @param string        $label       step label.
	 * @param string[]      $setting_ids referenced setting ids.
	 * @param callable|null $on_save     optional idempotent save side-effect.
	 * @return void
	 */
	protected function register_step( string $id, string $label, array $setting_ids, ?callable $on_save = null ): void {
		$this->steps[ $id ] = Step::settings( $id, $label, $setting_ids, $on_save );
	}

	/**
	 * Registers a content/action step (no fields).
	 *
	 * @since 2.0.2
	 *
	 * @param string          $id      step id.
	 * @param string          $label   step label.
	 * @param callable|string $content content callback or markup.
	 * @return void
	 */
	protected function register_content_step( string $id, string $label, $content ): void {
		$this->steps[ $id ] = Step::content( $id, $label, $content );
	}

	/**
	 * Wires base-owned hooks. Filled in later tasks (trigger, notice, page, REST).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function add_hooks(): void {}

	/** @since 2.0.2 @return string */
	public function get_id(): string {
		return $this->plugin->get_id();
	}

	/** @since 2.0.2 @return \Woodev_Plugin */
	public function get_plugin(): \Woodev_Plugin {
		return $this->plugin;
	}

	/** @since 2.0.2 @return Step[] */
	public function get_steps(): array {
		return $this->steps;
	}

	/** @since 2.0.2 @return bool */
	public function has_steps(): bool {
		return ! empty( $this->steps );
	}

	/** @since 2.0.2 @return string */
	public function get_required_capability(): string {
		return $this->required_capability;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardRegistryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-setup-wizard.php tests/unit/SetupWizardRegistryTest.php
git commit -m "feat(setup): add neutral Setup_Wizard step registry"
```

---

## Task 3: `Setup_Wizard` — completion state

**Files:**
- Modify: `woodev/setup/class-setup-wizard.php`
- Test: `tests/unit/SetupWizardStateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class State_Test_Wizard extends Setup_Wizard {
	public function __construct() {}
	protected function register_steps(): void {}
	public function get_id(): string { return 'acme'; }
}

class SetupWizardStateTest extends TestCase {

	public function test_is_complete_reads_option(): void {
		Functions\expect( 'get_option' )
			->once()->with( 'woodev_acme_setup_wizard_complete', '' )
			->andReturn( 'completed' );

		$wizard = new State_Test_Wizard();
		$this->assertTrue( $wizard->is_complete() );
		$this->assertFalse( $wizard->is_skipped() );
	}

	public function test_complete_setup_writes_option(): void {
		Functions\expect( 'update_option' )
			->once()->with( 'woodev_acme_setup_wizard_complete', 'skipped' );

		$wizard = new State_Test_Wizard();
		$wizard->complete_setup( 'skipped' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardStateTest.php`
Expected: FAIL — `is_complete()` not defined.

- [ ] **Step 3: Add the state methods to `Setup_Wizard`**

Insert before the closing brace of the class:

```php
	/**
	 * Option name storing completion state ('' | 'completed' | 'skipped').
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_complete_option_name(): string {
		return "woodev_{$this->get_id()}_setup_wizard_complete";
	}

	/** @since 2.0.2 @return string */
	public function get_state(): string {
		return (string) get_option( $this->get_complete_option_name(), '' );
	}

	/** @since 2.0.2 @return bool */
	public function is_complete(): bool {
		return 'completed' === $this->get_state();
	}

	/** @since 2.0.2 @return bool */
	public function is_skipped(): bool {
		return 'skipped' === $this->get_state();
	}

	/** @since 2.0.2 @return bool */
	public function is_finished(): bool {
		return '' !== $this->get_state();
	}

	/**
	 * Persists completion state (server-side authority, not a client flag).
	 *
	 * @since 2.0.2
	 *
	 * @param string $state 'completed' (default) or 'skipped'.
	 * @return void
	 */
	public function complete_setup( string $state = 'completed' ): void {
		update_option( $this->get_complete_option_name(), 'skipped' === $state ? 'skipped' : 'completed' );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardStateTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-setup-wizard.php tests/unit/SetupWizardStateTest.php
git commit -m "feat(setup): add Setup_Wizard completion state"
```

---

## Task 4: `Setup_Wizard` — first-install trigger & redirect guard

**Files:**
- Modify: `woodev/setup/class-setup-wizard.php`
- Test: `tests/unit/SetupWizardTriggerTest.php`

- [ ] **Step 1: Write the failing test** (the redirect decision is pure logic — test it directly)

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class Trigger_Test_Wizard extends Setup_Wizard {
	public bool $complete = false;
	public function __construct() {}
	protected function register_steps(): void {}
	public function get_id(): string { return 'acme'; }
	public function is_finished(): bool { return $this->complete; }
	// expose the guard
	public function should() : bool { return $this->should_redirect_on_admin_init(); }
}

class SetupWizardTriggerTest extends TestCase {

	private function base_env(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 1 );
	}

	public function test_redirects_for_single_fresh_install(): void {
		$this->base_env();
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$this->assertTrue( $wizard->should() );
	}

	public function test_no_redirect_on_bulk_activation(): void {
		$this->base_env();
		$_GET = [ 'activate-multi' => '1' ];
		$wizard = new Trigger_Test_Wizard();
		$this->assertFalse( $wizard->should() );
		$_GET = [];
	}

	public function test_no_redirect_when_already_finished(): void {
		$this->base_env();
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$wizard->complete = true;
		$this->assertFalse( $wizard->should() );
	}

	public function test_no_redirect_without_transient(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$this->assertFalse( $wizard->should() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardTriggerTest.php`
Expected: FAIL — `should_redirect_on_admin_init()` not defined.

- [ ] **Step 3: Add the trigger/guard methods to `Setup_Wizard`**

```php
	/**
	 * Transient name for the one-shot post-install redirect.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_redirect_transient_name(): string {
		return "woodev_{$this->get_id()}_setup_wizard_redirect";
	}

	/**
	 * Arms the one-shot redirect on first install.
	 *
	 * Hooked to woodev_{id}_installed (Woodev_Lifecycle).
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function handle_installed(): void {
		set_transient( $this->get_redirect_transient_name(), 1, HOUR_IN_SECONDS );
	}

	/**
	 * Decides whether admin_init should redirect to the wizard this request.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function should_redirect_on_admin_init(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( isset( $_GET['activate-multi'] ) ) { // bulk activation — ambiguous target.
			return false;
		}

		if ( $this->is_finished() ) {
			return false;
		}

		return (bool) get_transient( $this->get_redirect_transient_name() );
	}

	/**
	 * Performs the one-shot redirect to the wizard page.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! $this->should_redirect_on_admin_init() ) {
			return;
		}

		delete_transient( $this->get_redirect_transient_name() );
		wp_safe_redirect( $this->get_setup_url() );
		exit;
	}
```

Wire them in `add_hooks()` (replace the empty body):

```php
	protected function add_hooks(): void {
		add_action( "woodev_{$this->get_id()}_installed", [ $this, 'handle_installed' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );

		add_filter(
			'plugin_action_links_' . plugin_basename( $this->plugin->get_plugin_file() ),
			[ $this, 'add_action_link' ],
			20
		);
	}
```

> `get_setup_url()`, `maybe_render_notice()`, `register_page()`, `register_rest()`, `add_action_link()` are added in Tasks 5–6; until then this is wired but those methods land next. Keep tests green by running only the trigger test file in Step 4.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardTriggerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-setup-wizard.php tests/unit/SetupWizardTriggerTest.php
git commit -m "feat(setup): add first-install redirect guard"
```

---

## Task 5: `Setup_Wizard` — page, bootstrap data, notice, action link

**Files:**
- Modify: `woodev/setup/class-setup-wizard.php`
- Test: `tests/unit/SetupWizardBootstrapTest.php`

- [ ] **Step 1: Write the failing test** (the bootstrap-data builder is the testable unit)

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class Bootstrap_Test_Wizard extends Setup_Wizard {
	public function __construct() {}
	protected function register_steps(): void {
		$this->register_content_step( 'welcome', 'Привет', static function (): string { return ''; } );
		$this->register_step( 'connection', 'Подключение', [ 'api_key' ] );
	}
	public function get_id(): string { return 'acme'; }
	public function build(): void { $this->build_steps(); }
	public function data(): array { return $this->get_bootstrap_data(); }
	protected function get_field_schema(): array { return [ 'api_key' => [ 'type' => 'string', 'value' => 'k' ] ]; }
	protected function get_setup_url(): string { return 'https://x/wp-admin/admin.php?page=woodev-acme-setup'; }
	public function get_state(): string { return ''; }
}

class SetupWizardBootstrapTest extends TestCase {

	public function test_bootstrap_data_shape(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'rest_url' )->justReturn( 'https://x/wp-json/' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );

		$wizard = new Bootstrap_Test_Wizard();
		$wizard->build();
		$data = $wizard->data();

		$this->assertSame( 'acme', $data['pluginId'] );
		$this->assertSame( 'NONCE', $data['nonce'] );
		$this->assertSame( [ 'welcome', 'connection' ], array_column( $data['steps'], 'id' ) );
		// content step carries no fields; settings step carries its schema slice.
		$this->assertSame( [], $data['steps'][0]['fields'] );
		$this->assertArrayHasKey( 'api_key', $data['steps'][1]['fields'] );
		$this->assertStringContainsString( 'woodev/v1/acme/setup', $data['restRoot'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardBootstrapTest.php`
Expected: FAIL — `get_bootstrap_data()` not defined.

- [ ] **Step 3: Add page/bootstrap/notice/link methods to `Setup_Wizard`**

```php
	/** @since 2.0.2 @return string */
	public function get_page_slug(): string {
		return "woodev-{$this->get_id()}-setup";
	}

	/** @since 2.0.2 @return string */
	public function get_setup_url(): string {
		return esc_url_raw( admin_url( 'admin.php?page=' . $this->get_page_slug() ) );
	}

	/**
	 * Registers the hidden full-screen wizard admin page.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			'', // hidden: no parent menu.
			$this->plugin->get_plugin_name(),
			$this->plugin->get_plugin_name(),
			$this->required_capability,
			$this->get_page_slug(),
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "admin_print_scripts-{$hook}", [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Renders the React mount node for the wizard.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div id="woodev-setup-wizard-root"></div>';
		echo '<noscript><p>' . esc_html__( 'Для мастера настройки нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ) . '</p></noscript>';
	}

	/**
	 * Enqueues the wizard React bundle + inline bootstrap.
	 *
	 * Mirrors Woodev_Admin_Pages::load_licenses_page_scripts().
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$asset_file = $this->plugin->get_framework_path() . '/assets/build/setup-wizard/index.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: [ 'dependencies' => [], 'version' => $this->plugin->get_version() ];

		$build_url = $this->plugin->get_framework_assets_url() . '/build/setup-wizard';

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-setup-wizard', $build_url . '/style-index.css', [ 'wp-components' ], $asset['version'] );
		wp_enqueue_script( 'woodev-setup-wizard', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );

		wp_add_inline_script(
			'woodev-setup-wizard',
			'window.woodevSetupWizard = ' . wp_json_encode( $this->get_bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * Builds the PHP-driven bootstrap payload for the React shell.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function get_bootstrap_data(): array {
		$schema = $this->get_field_schema();
		$steps  = [];

		foreach ( $this->steps as $step ) {
			$fields = [];
			foreach ( $step->get_setting_ids() as $sid ) {
				if ( isset( $schema[ $sid ] ) ) {
					$fields[ $sid ] = $schema[ $sid ];
				}
			}

			$steps[] = [
				'id'     => $step->get_id(),
				'label'  => $step->get_label(),
				'type'   => $step->get_type(),
				'fields' => $fields,
			];
		}

		return [
			'pluginId'      => $this->get_id(),
			'pluginName'    => $this->plugin->get_plugin_name(),
			'headerLogoUrl' => esc_url_raw( $this->get_header_image_url() ),
			'restRoot'      => esc_url_raw( rest_url( "woodev/v1/{$this->get_id()}/setup" ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'state'         => $this->get_state(),
			'steps'         => $steps,
			'finishActions' => $this->get_finish_actions(),
		];
	}

	/**
	 * Resolves the JSON field schema for referenced settings from the plugin's
	 * Settings API handler. Returns an empty map when the plugin has no handler.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_field_schema(): array {
		$handler = $this->plugin->get_settings_handler();
		if ( ! $handler ) {
			return [];
		}

		$schema = [];
		foreach ( $handler->get_settings() as $setting ) {
			$schema[ $setting->get_id() ] = [
				'type'    => $setting->get_type(),
				'name'    => $setting->get_name(),
				'options' => $setting->get_options(),
				'value'   => $handler->get_value( $setting->get_id() ),
			];
		}

		return $schema;
	}

	/**
	 * Finish-screen "what's next" actions. Override per plugin.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_finish_actions(): array {
		$actions = [];
		if ( $this->plugin->get_documentation_url() ) {
			$actions[] = [
				'label' => __( 'Документация', 'woodev-plugin-framework' ),
				'url'   => esc_url_raw( $this->plugin->get_documentation_url() ),
			];
		}

		return $actions;
	}

	/**
	 * Header logo URL. Override per plugin.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_header_image_url(): string {
		return '';
	}

	/**
	 * Renders the "run the wizard" / "read the docs" admin notice fallback.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_render_notice(): void {
		if ( $this->is_finished() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-primary">%3$s</a></p></div>',
			esc_html( sprintf( /* translators: %s plugin name */ __( 'Завершите настройку %s.', 'woodev-plugin-framework' ), $this->plugin->get_plugin_name() ) ),
			esc_url( $this->get_setup_url() ),
			esc_html__( 'Запустить мастер настройки', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Adds a "Setup" link to the plugin row while incomplete.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $links existing action links.
	 * @return string[]
	 */
	public function add_action_link( array $links ): array {
		if ( ! $this->is_finished() ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $this->get_setup_url() ), esc_html__( 'Настройка', 'woodev-plugin-framework' ) );
		}

		return $links;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardBootstrapTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-setup-wizard.php tests/unit/SetupWizardBootstrapTest.php
git commit -m "feat(setup): add wizard page, bootstrap data, notice, action link"
```

---

## Task 6: `Woodev_REST_API_Setup` controller

**Files:**
- Create: `woodev/rest-api/controllers/class-rest-api-setup.php`
- Modify: `woodev/setup/class-setup-wizard.php` (add `register_rest()`)
- Test: `tests/unit/SetupWizardRestControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-setup.php';

class SetupWizardRestControllerTest extends TestCase {

	public function test_permission_check_uses_wizard_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_required_capability' )->andReturn( 'manage_options' );

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$this->assertFalse( $controller->permissions_check() );
	}

	public function test_complete_sets_state_and_returns_ok(): void {
		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'complete_setup' )->once()->with( 'completed' );

		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'state' )->andReturn( 'completed' );

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$response   = $controller->complete( $request );

		$this->assertSame( [ 'complete' => true, 'state' => 'completed' ], $response );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardRestControllerTest.php`
Expected: FAIL — class `Woodev_REST_API_Setup` not found.

- [ ] **Step 3: Implement the controller**

```php
<?php
/**
 * Setup wizard REST controller (woodev/v1).
 *
 * @package Woodev\Framework\REST
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the wizard bootstrap, persists per-step values, and finalizes setup.
 *
 * Registered through Woodev_REST_V1_Registrar (neutral woodev/v1 namespace).
 *
 * @since 2.0.2
 */
class Woodev_REST_API_Setup {

	/** @var \Woodev\Framework\Setup\Setup_Wizard wizard handler. */
	private $wizard;

	/**
	 * @since 2.0.2
	 *
	 * @param \Woodev\Framework\Setup\Setup_Wizard $wizard wizard handler.
	 */
	public function __construct( $wizard ) {
		$this->wizard = $wizard;
	}

	/**
	 * Registers the wizard routes.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id   = $this->wizard->get_id();
		$base = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

		register_rest_route(
			$base,
			"/{$id}/setup/steps/(?P<step_id>[\w-]+)",
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_step' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		register_rest_route(
			$base,
			"/{$id}/setup/complete",
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'complete' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	/**
	 * Capability gate mirroring the wizard page.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return current_user_can( $this->wizard->get_required_capability() );
	}

	/**
	 * Validates + persists one step's values, then runs the optional on_save.
	 *
	 * Settings are persisted BEFORE on_save; a thrown on_save reports an error
	 * while settings are already saved (on_save must be idempotent).
	 *
	 * @since 2.0.2
	 *
	 * @param \WP_REST_Request $request request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_step( $request ) {
		$step_id = (string) $request->get_param( 'step_id' );
		$step    = $this->wizard->get_steps()[ $step_id ] ?? null;

		if ( null === $step ) {
			return new WP_Error( 'woodev_setup_unknown_step', __( 'Неизвестный шаг.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}

		$handler = $this->wizard->get_plugin()->get_settings_handler();
		$values  = (array) $request->get_param( 'values' );

		if ( $handler ) {
			foreach ( $step->get_setting_ids() as $sid ) {
				if ( array_key_exists( $sid, $values ) ) {
					try {
						// Woodev_Abstract_Settings::update_value() validates (throws
						// Woodev_Plugin_Exception on invalid/out-of-options/missing)
						// AND persists via save() internally — no separate save() call.
						$handler->update_value( $sid, $values[ $sid ] );
					} catch ( \Woodev_Plugin_Exception $e ) {
						return new WP_Error(
							'woodev_setup_invalid',
							$e->getMessage(),
							[ 'status' => $e->getCode() ?: 400, 'field' => $sid ]
						);
					}
				}
			}
		}

		$on_save = $step->get_on_save();
		if ( is_callable( $on_save ) ) {
			try {
				call_user_func( $on_save, $values, $request );
			} catch ( \Exception $e ) {
				return new WP_Error( 'woodev_setup_step_failed', $e->getMessage(), [ 'status' => 400 ] );
			}
		}

		return rest_ensure_response( [ 'saved' => true, 'step' => $step_id ] );
	}

	/**
	 * Finalizes the wizard (server-side authority).
	 *
	 * @since 2.0.2
	 *
	 * @param \WP_REST_Request $request request.
	 * @return \WP_REST_Response|array
	 */
	public function complete( $request ) {
		$state = 'skipped' === $request->get_param( 'state' ) ? 'skipped' : 'completed';
		$this->wizard->complete_setup( $state );

		return rest_ensure_response( [ 'complete' => true, 'state' => $state ] );
	}
}
```

> Contract (verified against source): `Woodev_Setting::validate_value(): bool`; `Woodev_Abstract_Settings::update_value( $id, $value )` validates internally and **throws `Woodev_Plugin_Exception`** (code 400 invalid / out-of-options, 404 missing) and persists via `save()` itself. Hence the single `try { update_value } catch ( Woodev_Plugin_Exception )` above — no separate validate/save calls.

- [ ] **Step 4: Add `register_rest()` to `Setup_Wizard`**

```php
	/**
	 * Registers the wizard REST controller through the woodev/v1 registrar.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_rest(): void {
		if ( ! class_exists( 'Woodev_REST_API_Setup' ) ) {
			require_once $this->plugin->get_framework_path() . '/rest-api/controllers/class-rest-api-setup.php';
		}

		\Woodev_REST_V1_Registrar::register_controller( new \Woodev_REST_API_Setup( $this ) );
	}
```

(The `add_hooks()` wiring from Task 4 already calls `register_rest` on `rest_api_init`; since the registrar itself hooks `rest_api_init`, register the controller early — call `register_rest()` directly in `add_hooks()` instead of on the hook if registration timing requires it. Verify during implementation; the registrar dedupes.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SetupWizardRestControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-setup.php woodev/setup/class-setup-wizard.php tests/unit/SetupWizardRestControllerTest.php
git commit -m "feat(setup): add woodev/v1 setup REST controller"
```

---

## Task 7: `Woocommerce_Setup_Wizard` thin subclass

**Files:**
- Create: `woodev/setup/class-woocommerce-setup-wizard.php`
- Test: `tests/unit/WoocommerceSetupWizardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Woocommerce_Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-woocommerce-setup-wizard.php';

class WC_Test_Wizard extends Woocommerce_Setup_Wizard {
	public function __construct() {}
	protected function register_steps(): void {}
}

class WoocommerceSetupWizardTest extends TestCase {

	public function test_capability_is_manage_woocommerce(): void {
		$wizard = new WC_Test_Wizard();
		$this->assertSame( 'manage_woocommerce', $wizard->get_required_capability() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/WoocommerceSetupWizardTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the WC subclass**

```php
<?php
/**
 * WooCommerce setup wizard.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Thin WooCommerce specialization: WC capability, WC-active gate, ready-made
 * WC-readiness checks. WC-specific callbacks may safely call WC functions.
 *
 * @since 2.0.2
 */
abstract class Woocommerce_Setup_Wizard extends Setup_Wizard {

	/** @var string WC capability. */
	protected string $required_capability = 'manage_woocommerce';

	/**
	 * Wires hooks only when WooCommerce is active.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function add_hooks(): void {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		parent::add_hooks();
	}

	/**
	 * Whether WooCommerce is active in this request.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Helper: whether at least one WC shipping zone (beyond "rest of world")
	 * is configured. For shipping plugins' readiness check steps.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function are_shipping_zones_configured(): bool {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return false;
		}

		return ! empty( \WC_Shipping_Zones::get_zones() );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/WoocommerceSetupWizardTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add woodev/setup/class-woocommerce-setup-wizard.php tests/unit/WoocommerceSetupWizardTest.php
git commit -m "feat(setup): add thin Woocommerce_Setup_Wizard"
```

---

## Task 8: Base wiring in `Woodev_Plugin`

**Files:**
- Modify: `woodev/class-plugin.php`
- Test: `tests/unit/PlatformNeutralSetupWizardTest.php` (rewrite — Task 9 also touches it; do the rewrite here)

- [ ] **Step 1: Rewrite the neutral wizard test**

Replace the whole file `tests/unit/PlatformNeutralSetupWizardTest.php`:

```php
<?php
/**
 * Platform-neutral setup wizard tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class Neutral_Probe_Wizard extends Setup_Wizard {
	public function __construct() {}
	protected function register_steps(): void {}
}

class PlatformNeutralSetupWizardTest extends TestCase {

	public function test_base_wizard_default_capability_is_not_wc(): void {
		$wizard = new Neutral_Probe_Wizard();
		$this->assertSame( 'manage_options', $wizard->get_required_capability() );
	}

	public function test_base_wizard_declares_no_woocommerce_methods(): void {
		$methods = get_class_methods( Setup_Wizard::class );
		foreach ( $methods as $method ) {
			$this->assertStringNotContainsStringIgnoringCase( 'woocommerce', $method );
			$this->assertStringNotContainsStringIgnoringCase( 'hpos', $method );
		}
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/PlatformNeutralSetupWizardTest.php`
Expected: PASS already for the two assertions (classes exist from Tasks 1–2). If the old file still referenced the legacy class, this rewrite removes that dependency. Proceed.

- [ ] **Step 3: Update `Woodev_Plugin`**

In `woodev/class-plugin.php`:

1. Replace the dead `init_setup_wizard_handler()` (currently `require_once`s the legacy abstract) — delete the method entirely and its call in the constructor (search `init_setup_wizard_handler`).
2. Add an opt-in getter + init mirroring `get_competitor_notification_handler()`:

```php
	/** @var \Woodev\Framework\Setup\Setup_Wizard|null */
	protected $setup_wizard_handler;

	/**
	 * Builds the setup wizard handler (opt-in).
	 *
	 * Plugins opt in by overriding get_setup_wizard_handler() to return their
	 * Setup_Wizard subclass; the default returns null (no wizard).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function init_setup_wizard_handler(): void {
		$this->setup_wizard_handler = $this->get_setup_wizard_handler();
	}

	/**
	 * Gets the plugin's setup wizard handler, or null when not opted in.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev\Framework\Setup\Setup_Wizard|null
	 */
	public function get_setup_wizard_handler() {
		return $this->setup_wizard_handler ?? null;
	}
```

> The existing `get_setup_wizard_handler()` (returns `$this->setup_wizard_handler`) and the constructor call to `init_setup_wizard_handler()` already exist (CURRENT-STATE / grounding). Keep the constructor call; it now assigns the opt-in handler instead of requiring the legacy file. Plugins override `get_setup_wizard_handler()` to return `new Plugin_Setup_Wizard( $this )`, whose constructor self-wires hooks (Tasks 2–6).

Wait — overriding `get_setup_wizard_handler()` AND having `init_setup_wizard_handler()` call it creates recursion only if the base default reads the property the init sets. To avoid confusion: make the **plugin override `get_setup_wizard_handler()`** to construct-and-return its wizard, and have `init_setup_wizard_handler()` simply call it once and store the result. The base default getter returns the stored handler. Concretely:

- Base: `init_setup_wizard_handler()` → `$this->setup_wizard_handler = $this->build_setup_wizard_handler();`
- Base: `protected function build_setup_wizard_handler() { return null; }` (opt-in seam).
- Base: `public function get_setup_wizard_handler() { return $this->setup_wizard_handler; }`
- Plugin overrides `build_setup_wizard_handler()` → `return new Plugin_Setup_Wizard( $this );`

Use this 3-method shape (getter / init / build seam) to match the competitor pattern exactly and avoid recursion. Update the snippet above accordingly when implementing.

- [ ] **Step 4: Run the unit suite**

Run: `composer test:unit`
Expected: PASS (all suites green, including the rewritten neutral test).

- [ ] **Step 5: Commit**

```bash
git add woodev/class-plugin.php tests/unit/PlatformNeutralSetupWizardTest.php
git commit -m "refactor(setup): wire opt-in get_setup_wizard_handler in base plugin"
```

---

## Task 9: Remove legacy wizard + update fixtures + autoload

**Files:**
- Delete: `woodev/admin/abstract-plugin-admin-setup-wizard.php`
- Modify: 4 fixtures (remove `init_setup_wizard_handler(){}` overrides)
- Modify: `composer.json` (classmap)
- Regenerate: `woodev/class-map.php`

- [ ] **Step 1: Delete the legacy class**

```bash
git rm woodev/admin/abstract-plugin-admin-setup-wizard.php
```

- [ ] **Step 2: Remove the fixture stubs**

In each of these files, delete the `protected function init_setup_wizard_handler() {}` override (the base now defaults to no wizard via the opt-in seam):
- `tests/_fixtures/woodev-realistic-payment-plugin/includes/class-realistic-payment-plugin.php`
- `tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php`
- `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-shipping-plugin.php`
- `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-plugin.php`

- [ ] **Step 3: Add `woodev/setup` to the composer classmap (dev/test)**

In `composer.json` `autoload.classmap`, add the line `"woodev/setup",` (alphabetical, near `woodev/settings-api`). Then:

```bash
composer dump-autoload
```

- [ ] **Step 4: Regenerate the runtime class map**

Run:
```bash
php bin/generate-class-map.php
git diff --stat woodev/class-map.php
```
Expected: `woodev/class-map.php` gains entries for `Woodev\Framework\Setup\Step`, `Setup_Wizard`, `Woocommerce_Setup_Wizard`, and `Woodev_REST_API_Setup`; the legacy `Woodev_Plugin_Setup_Wizard` entry is removed.

- [ ] **Step 5: Run the full check**

Run: `composer test:unit && composer phpcs`
Expected: PASS / no style violations.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(setup): remove legacy setup wizard, update fixtures + class map"
```

---

## Task 10: React shell

**Files:**
- Create: `src/setup-wizard/index.js`, `app.js`, `step-view.js`, `control-field.js`, `progress.js`, `rest.js`, `style.scss`
- Build output: `woodev/assets/build/setup-wizard/*`

> No JS unit tests (parity with license-page/plugins-page). Use the classic JSX runtime: import `createElement`/`Fragment` and use them (gotcha `wp-scripts-jsx-runtime-wp66`); the project `babel.config.js` already forces classic runtime.

- [ ] **Step 1: Create `src/setup-wizard/rest.js`**

```js
import apiFetch from '@wordpress/api-fetch';

const { restRoot, nonce } = window.woodevSetupWizard;

export function saveStep( stepId, values ) {
	return apiFetch( {
		url: `${ restRoot }/steps/${ stepId }`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { values },
	} );
}

export function complete( state = 'completed' ) {
	return apiFetch( {
		url: `${ restRoot }/complete`,
		method: 'POST',
		headers: { 'X-WP-Nonce': nonce },
		data: { state },
	} );
}
```

- [ ] **Step 2: Create `src/setup-wizard/control-field.js`** (renders one field by Settings type)

```js
import { createElement } from '@wordpress/element';
import { TextControl, ToggleControl, SelectControl } from '@wordpress/components';

export default function ControlField( { id, schema, value, onChange } ) {
	if ( 'boolean' === schema.type ) {
		return createElement( ToggleControl, { label: schema.name, checked: !! value, onChange } );
	}
	if ( schema.options && Object.keys( schema.options ).length ) {
		const options = Object.entries( schema.options ).map( ( [ k, v ] ) => ( { label: v, value: k } ) );
		return createElement( SelectControl, { label: schema.name, value, options, onChange } );
	}
	return createElement( TextControl, { label: schema.name, value: value ?? '', onChange } );
}
```

- [ ] **Step 3: Create `step-view.js`, `progress.js`, `app.js`, `index.js`**

`index.js` (mount):
```js
import { createElement, render } from '@wordpress/element';
import App from './app';
import './style.scss';

const root = document.getElementById( 'woodev-setup-wizard-root' );
if ( root ) {
	render( createElement( App ), root );
}
```

`app.js` (state machine: current step, values, save→advance, back, finish):
```js
import { createElement, Fragment, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import StepView from './step-view';
import Progress from './progress';
import { saveStep, complete } from './rest';

export default function App() {
	const { steps, finishActions, pluginName } = window.woodevSetupWizard;
	const [ index, setIndex ] = useState( 0 );
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ done, setDone ] = useState( false );

	const step = steps[ index ];
	const isLast = index === steps.length - 1;

	async function next() {
		setError( null );
		try {
			if ( 'settings' === step.type ) {
				await saveStep( step.id, values[ step.id ] || {} );
			}
			if ( isLast ) {
				await complete( 'completed' );
				setDone( true );
			} else {
				setIndex( index + 1 );
			}
		} catch ( e ) {
			setError( e.message || 'Ошибка сохранения' );
		}
	}

	if ( done ) {
		return createElement( 'div', { className: 'woodev-setup-finish' },
			createElement( 'h1', null, `${ pluginName } готов!` ),
			finishActions.map( ( a, i ) => createElement( Button, { key: i, variant: 'primary', href: a.url }, a.label ) )
		);
	}

	return createElement( Fragment, null,
		createElement( Progress, { steps, index } ),
		error && createElement( Notice, { status: 'error', isDismissible: false }, error ),
		createElement( StepView, {
			step,
			values: values[ step.id ] || {},
			onChange: ( v ) => setValues( { ...values, [ step.id ]: v } ),
		} ),
		createElement( 'div', { className: 'woodev-setup-actions' },
			index > 0 && createElement( Button, { onClick: () => setIndex( index - 1 ) }, 'Назад' ),
			createElement( Button, { variant: 'primary', onClick: next }, isLast ? 'Завершить' : 'Продолжить' ),
			! isLast && createElement( Button, { variant: 'link', onClick: async () => { await complete( 'skipped' ); setDone( true ); } }, 'Пропустить' )
		)
	);
}
```

`step-view.js` (renders content step markup or settings fields):
```js
import { createElement, Fragment } from '@wordpress/element';
import ControlField from './control-field';

export default function StepView( { step, values, onChange } ) {
	return createElement( Fragment, null,
		createElement( 'h2', null, step.label ),
		'settings' === step.type
			? Object.entries( step.fields ).map( ( [ id, schema ] ) =>
				createElement( ControlField, {
					key: id, id, schema,
					value: values[ id ] ?? schema.value,
					onChange: ( v ) => onChange( { ...values, [ id ]: v } ),
				} ) )
			: createElement( 'div', { dangerouslySetInnerHTML: { __html: step.content || '' } } )
	);
}
```

`progress.js`:
```js
import { createElement } from '@wordpress/element';

export default function Progress( { steps, index } ) {
	return createElement( 'ol', { className: 'woodev-setup-steps' },
		steps.map( ( s, i ) => createElement( 'li', {
			key: s.id,
			className: i === index ? 'active' : ( i < index ? 'done' : '' ),
		}, s.label ) )
	);
}
```

`style.scss`: minimal full-screen layout (centered card, step list). Keep step styles in this bundle (lesson `license-page-css-bundle-only`).

> `content` steps render server-provided markup via `dangerouslySetInnerHTML`; since the markup originates from the plugin's own PHP callback (trusted, server-rendered), this is acceptable. Do not pass user input here.

- [ ] **Step 4: Build the bundle**

Confirm `package.json` build covers `src/setup-wizard` (the existing `wp-scripts build` with multiple entry points should pick it up; if entries are explicit, add `setup-wizard: 'src/setup-wizard/index.js'`). Run:

```bash
npm run build
ls woodev/assets/build/setup-wizard
```
Expected: `index.js`, `index.asset.php`, `style-index.css` present.

- [ ] **Step 5: Normalize EOL + commit**

Ensure LF in build artifacts (gotcha `build-artifacts-eol-lf-windows-parity`; `.gitattributes` pins `woodev/assets/build/** text eol=lf`). Then:

```bash
git add src/setup-wizard woodev/assets/build/setup-wizard package.json
git commit -m "feat(setup): add React setup-wizard shell + build"
```

---

## Task 11: Integration test

**Files:**
- Create: `tests/integration/SetupWizardRestTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php
namespace Woodev\Tests\Integration;

/**
 * @group setup-wizard
 */
class SetupWizardRestTest extends TestCase {

	public function test_routes_are_registered_under_woodev_v1(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/woodev/v1/test/setup/complete', $routes );
	}

	public function test_complete_requires_capability(): void {
		// Editor: reaches wp-admin but lacks manage_options/manage_woocommerce.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new \WP_REST_Request( 'POST', '/woodev/v1/test/setup/complete' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
```

> The integration TestCase must boot a fixture plugin that opts into a wizard with id `test` (add a minimal wizard to an existing fixture, or a dedicated `woodev-setup-pilot-plugin` fixture mapped per gotcha `wpenv-resolver-fixture-mapping`). Use an **editor**, not a subscriber, for the 403 check (gotcha `wc-blocks-subscriber-wp-admin-403-test`).

- [ ] **Step 2: Run the integration suite**

Run: `composer test:integration` (requires wp-env; on Windows Git-Bash use the path-mangling workaround — gotcha `wpenv-windows-gitbash-path-mangling`).
Expected: PASS (2 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/integration/SetupWizardRestTest.php tests/_fixtures
git commit -m "test(setup): integration coverage for wizard REST routes"
```

---

## Task 12: Final verification + PR

- [ ] **Step 1: Full local gate**

Run: `composer test:unit && composer phpcs`
Expected: green. (PHPStan only on Linux CI — gotcha `phpstan-windows-parallel-worker-segfault`.)

- [ ] **Step 2: Push + open PR**

```bash
git push -u origin feature/setup-wizard-ob10
gh pr create --fill --base main
```

- [ ] **Step 3: Verify CI**

Confirm the Unit matrix **and** the "Run PHPStan" job both pass (gotcha `ci-failing-gate-skips-dependent-jobs` — a skipped Lint silently skips Unit). Then squash-merge:

```bash
gh pr merge <N> --squash --delete-branch
```
(Never `--auto` — gotcha-confirmed in s11.)

- [ ] **Step 4: Codex review (architecturally-sensitive)**

Run a Codex review pass on the wizard (`/codex:review` or background companion). Present findings verbatim; do not auto-fix — ask the operator which to apply.

- [ ] **Step 5: Rig / browser verification**

Operator-driven: install a fixture plugin opted into the wizard, confirm first-install redirect, step navigation, save, completion, notice-fallback after bulk activation, skip behavior.

---

## Self-Review Notes

- **Spec coverage:** D1 (declarative steps → Task 2), D2 (Settings API reuse → Tasks 5/6 `get_field_schema`/`save_step`), D3 (scope boundary → no settings-page work), D4 (explicit steps → Task 2 `register_step`), D5 (install trigger + notice → Tasks 4/5), D6 (WC wrapper → Task 7). Error handling (spec §7) → Task 6 (validation, on_save ordering, 403) + Task 5 (noscript). Legacy removal (spec §10) → Task 9.
- **Settings contract verified against source:** `validate_value(): bool`; `Woodev_Abstract_Settings::update_value()` validates (throws `Woodev_Plugin_Exception`) + persists itself — Task 6 `save_step` uses a single try/catch (resolved, no open verification).
- **Type consistency:** `Step::settings/content`, `get_setting_ids()`, `get_required_capability()`, `complete_setup($state)`, `get_steps()` used consistently across Tasks 1–8.
