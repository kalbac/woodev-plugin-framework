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
	 * Builds a provider descriptor from an UNTYPED section list — the permissive legacy seam.
	 *
	 * `$sections` is stored verbatim; nothing here checks that its elements are actually
	 * `Settings_Section` instances. A wrong-typed entry is not rejected here — it is dropped
	 * later, silently, on read, by {@see self::get_sections()}, which is documented
	 * `Settings_Section[]` but only ever RETURNS a class-checked subset of what was stored
	 * here. That silence is deliberate (#514 m6): a fatal on the read path takes down the
	 * whole settings page, not just the offending tab.
	 *
	 * The cost is diagnostic: a caller who makes this mistake does not see an error, they
	 * see a MISSING section, and that costs as much to track down as any other silent loss
	 * (#570). Prefer {@see self::create_with_sections()}, which refuses the wrong type
	 * loudly, at this call site, enforced by PHP's own signature rather than a runtime
	 * check. This method stays exactly as it is: every production call site already passes
	 * real `Settings_Section` objects, and an untyped published seam some caller may still
	 * depend on is a reason to leave it alone, not to change it.
	 *
	 * @since 2.0.2
	 *
	 * @param string                    $id       tab id; blank falls back to the handler id.
	 * @param string                    $label    tab label.
	 * @param \Woodev_Abstract_Settings $handler  settings handler.
	 * @param Settings_Section[]        $sections section grouping; a non-`Settings_Section`
	 *                                            entry is accepted here and dropped later by
	 *                                            {@see self::get_sections()} without notice.
	 * @param array<string,mixed>       $args     optional: capability, legacy_option_key, legacy_page, supports.
	 * @return self
	 */
	public static function create( string $id, string $label, $handler, array $sections, array $args = [] ): self {
		return new self( $id, $label, $handler, $sections, $args );
	}


	/**
	 * Builds a provider descriptor from TYPED sections — the loud counterpart to
	 * {@see self::create()} (#570).
	 *
	 * `$sections` is a variadic, typed parameter: PHP itself refuses a call that passes
	 * anything other than a `Settings_Section` for one of them — a `TypeError`, raised at
	 * this call site, before a `Settings_Provider` is ever built. No `instanceof` check runs
	 * in this method's body; the signature IS the check. That is deliberately unlike
	 * {@see Settings_Section::create_tools()}, whose tool entries arrive as an untyped array
	 * with no per-element type to declare in a signature, so that method filters at runtime
	 * and reports with `_doing_it_wrong()` instead — there is no PHP-native way to make an
	 * untyped array's contents loud, only a variadic typed parameter achieves that.
	 *
	 * `$args` sits ahead of `$sections` here, unlike in {@see self::create()}: a variadic
	 * parameter must be the last one a PHP signature allows, so making `$sections` typed and
	 * variadic requires it to trail `$args`, not lead it.
	 *
	 * An empty call (`create_with_sections( $id, $label, $handler, $args )`) is valid — a
	 * provider legitimately has zero sections (see {@see self::create()}'s own tests) — a
	 * variadic parameter accepts zero arguments same as it accepts many.
	 *
	 * @since 2.0.2
	 *
	 * @param string                    $id          tab id; blank falls back to the handler id.
	 * @param string                    $label       tab label.
	 * @param \Woodev_Abstract_Settings $handler     settings handler.
	 * @param array<string,mixed>       $args        optional: capability, legacy_option_key, legacy_page, supports.
	 * @param Settings_Section          ...$sections section grouping; a non-`Settings_Section`
	 *                                               argument is a `TypeError`, not a silent drop.
	 * @return self
	 */
	public static function create_with_sections( string $id, string $label, $handler, array $args = [], Settings_Section ...$sections ): self {
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
	 * FILTERS ON READ, for the same reason {@see Settings_Section::get_tools()} does: this
	 * method has always DOCUMENTED `Settings_Section[]` while `create()` took an untyped
	 * array and stored it verbatim, so the return type was a promise nothing kept.
	 *
	 * That mattered once the section's own guarantee moved onto its accessor. A caller can
	 * hand `create()` any object that merely LOOKS like a section — `is_tools()` returning
	 * true, `get_tools()` returning junk — and it never passes through
	 * `Settings_Section::get_tools()` at all. Reproduced through the public `create()`, no
	 * reflection needed: `build_sections()` fatals with
	 * `Call to a member function to_array() on string`, and `run_tool()` with
	 * `Call to a member function get_id() on string` — a whole-settings-page fatal for every
	 * tab, from one plugin's malformed descriptor.
	 *
	 * With this filter every link in the chain is class-checked, and each check is nameable:
	 * `Settings_Page_Registry::get_provider(): ?Settings_Provider` and
	 * `build_sections( Settings_Provider $provider )` are enforced by PHP itself; this method
	 * filters the sections; `Settings_Section::get_tools()` filters the tools. No reader has
	 * to repeat a CLASS check, which is what #514 m6 was for.
	 *
	 * WHAT A CLASS FILTER BUYS, AND WHAT IT DOES NOT. `instanceof` answers "is this the type I
	 * publish", which is what stops a duck-typed impostor. It does NOT answer "was this object
	 * ever constructed": an instance hand-built past its own constructor with
	 * `newInstanceWithoutConstructor()` is a real one, passes here, and then fatals on its
	 * first typed-property read with `must not be accessed before initialization`. That is
	 * true of EVERY class in PHP with typed properties, it is true identically on `main`
	 * (measured — `build_sections()` fatals at `$section->get_id()` before it ever reaches a
	 * tool, so no tools guard ever protected against it), and no `instanceof` anywhere can
	 * change it. The guarantee here is the CLASS, deliberately, and that is the whole of it.
	 *
	 * Silent, like the tools filter and for the same reason: the actionable notice belongs at
	 * the registration call. `create()` keeps the array untyped as its published legacy
	 * seam — this filter is what still guards a reader against whatever slips through it —
	 * but the loud refusal now has somewhere to live: {@see self::create_with_sections()}
	 * (#570) rejects a wrong-typed section at the call site, via PHP's own signature.
	 *
	 * @since 2.0.2
	 *
	 * @return Settings_Section[]
	 */
	public function get_sections(): array {
		return array_values(
			array_filter(
				$this->sections,
				static function ( $section ): bool {
					return $section instanceof Settings_Section;
				}
			)
		);
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
