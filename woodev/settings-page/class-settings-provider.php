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
	 * With this filter the whole chain is typed end to end, and every link is checkable:
	 * `Settings_Page_Registry::get_provider(): ?Settings_Provider` and
	 * `build_sections( Settings_Provider $provider )` are enforced by PHP itself; this method
	 * guarantees the sections; `Settings_Section::get_tools()` guarantees the tools. No reader
	 * has to re-ask, which is what #514 m6 was for.
	 *
	 * Silent, like the tools filter and for the same reason: the actionable notice belongs at
	 * the registration call. Unlike tools, there is no `create_sections()` to put one in —
	 * `create()` takes the array untyped as its published seam — so a louder refusal here
	 * would be a contract change, and that is #570, not this fix.
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
