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

	/** @var string optional section description (shown under the sub-tab). */
	private string $description;

	/** @var bool whether this section is a self-contained connection block. */
	private bool $is_connection = false;

	/** @var string label for the block's primary action button (e.g. «Проверить»/«Подключить»). */
	private string $action_label = '';

	/**
	 * Whether this section is a registry-backed tools block (#505). Deliberately
	 * a separate flag from {@see self::$is_connection} rather than overloading
	 * it — the React branch a tools block renders is different from a
	 * connection block's.
	 *
	 * @var bool
	 */
	private bool $is_tools = false;

	/**
	 * Tool descriptors for a tools block. Empty unless {@see self::$is_tools}.
	 *
	 * @var array<int, \Woodev\Framework\Shipping\Settings\Shipping_Tool>
	 */
	private array $tools = [];

	/**
	 * Use one of the named constructors instead — {@see self::create()},
	 * {@see self::create_connection()} or {@see self::create_tools()}. Being private is what
	 * makes those three the ONLY doors into this type, which is in turn what lets
	 * `create_tools()` be the single validation point for tool descriptors (#514 m6).
	 *
	 * The positional shape here stays deliberately dumb: it assigns, and decides nothing.
	 * Naming the kind is the named constructors' job.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, array $setting_ids, string $description = '', bool $is_connection = false, string $action_label = '', bool $is_tools = false, array $tools = [] ) {
		$this->id            = $id;
		$this->label         = $label;
		$this->setting_ids   = array_values( $setting_ids );
		$this->description   = $description;
		$this->is_connection = $is_connection;
		$this->action_label  = $action_label;
		$this->is_tools       = $is_tools;
		$this->tools          = array_values( $tools );
	}

	/**
	 * Builds an ORDINARY section — a labelled group of setting fields.
	 *
	 * One of three named constructors, one per section KIND (#514 m6). The kind used to be
	 * spelled out at the call site as a run of positional booleans and spacer arguments
	 * (`create( 'tools', …, [], …, false, '', true, $tools )`), which let a caller set
	 * `is_connection` and `is_tools` at once and silently get a section that is neither
	 * shape the React side knows how to render. The kinds are mutually exclusive, so each
	 * one gets its own entry point and no call site can name two.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id          section id.
	 * @param string   $label       section label.
	 * @param string[] $setting_ids referenced setting ids. An EMPTY list means the section
	 *                              declares zero fields — never "all of them"; see
	 *                              {@see Settings_Page_Registry::build_sections()} for why
	 *                              that differs from the handler-level convention.
	 * @param string   $description optional description shown under the sub-tab.
	 * @return self
	 */
	public static function create( string $id, string $label, array $setting_ids, string $description = '' ): self {
		return new self( $id, $label, $setting_ids, $description );
	}

	/**
	 * Builds a CONNECTION section — a self-contained block whose primary output is an action
	 * button, with zero or more credential fields above it.
	 *
	 * `$action_label` is required and sits ahead of `$description` on purpose: a connection
	 * block with no button label renders a nameless button, so it is not an optional detail
	 * the way a description is.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id           section id.
	 * @param string   $label        section label.
	 * @param string[] $setting_ids  referenced setting ids; may be empty (a handshake block
	 *                               such as a carrier's LK widget has no input fields at all).
	 * @param string   $action_label label for the block's primary action button.
	 * @param string   $description  optional description shown under the sub-tab.
	 * @return self
	 */
	public static function create_connection( string $id, string $label, array $setting_ids, string $action_label, string $description = '' ): self {
		return new self( $id, $label, $setting_ids, $description, true, $action_label );
	}

	/**
	 * Builds a TOOLS section — a registry-backed block of actions over the tab's data (#505).
	 *
	 * Takes no setting ids: a tools block is fields-less by construction, which is what the
	 * whole kind means.
	 *
	 * THIS IS THE VALIDATION DOOR for tool descriptors (#514 m6, critic N3). The private
	 * constructor makes it the only way to build a section at all, so filtering here is what
	 * lets BOTH consumers of `get_tools()` — {@see Settings_Page_Registry::build_sections()}
	 * and `Woodev_REST_API_Settings_Page::run_tool()` — read the array without re-asking.
	 * A non-conforming entry is dropped exactly as the `FILTER_TOOLS` filter door drops one
	 * ({@see \Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry::collect()}), never
	 * thrown: a fatal on this path takes down the settings page for every tab, not just this
	 * section.
	 *
	 * @since 2.0.2
	 *
	 * @param string                                                        $id          section id.
	 * @param string                                                        $label       section label.
	 * @param array<int, \Woodev\Framework\Shipping\Settings\Shipping_Tool> $tools       tool descriptors; anything else is dropped with a notice.
	 * @param string                                                        $description optional description shown under the sub-tab.
	 * @return self
	 */
	public static function create_tools( string $id, string $label, array $tools, string $description = '' ): self {
		$conforming = [];

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof \Woodev\Framework\Shipping\Settings\Shipping_Tool ) {
				_doing_it_wrong(
					__METHOD__,
					'A Settings_Section tools entry does not implement Shipping_Tool; it was ignored.',
					'2.0.2'
				);
				continue;
			}

			$conforming[] = $tool;
		}

		return new self( $id, $label, [], $description, false, '', true, $conforming );
	}

	/**
	 * Returns the section description.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
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

	/**
	 * Whether this section is a self-contained connection block.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_connection(): bool {
		return $this->is_connection;
	}

	/**
	 * Returns the primary action button label for a connection block.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_action_label(): string {
		return $this->action_label;
	}

	/**
	 * Whether this section is a registry-backed tools block.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_tools(): bool {
		return $this->is_tools;
	}

	/**
	 * Returns the tool descriptors. Empty unless {@see self::is_tools()}.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int, \Woodev\Framework\Shipping\Settings\Shipping_Tool>
	 */
	public function get_tools(): array {
		return $this->tools;
	}
}
