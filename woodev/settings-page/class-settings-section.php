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
	 * Use the named constructor instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, array $setting_ids, string $description = '', bool $is_connection = false, string $action_label = '' ) {
		$this->id            = $id;
		$this->label         = $label;
		$this->setting_ids   = array_values( $setting_ids );
		$this->description   = $description;
		$this->is_connection = $is_connection;
		$this->action_label  = $action_label;
	}

	/**
	 * Builds a section.
	 *
	 * @since 2.0.2
	 *
	 * @param string   $id            section id.
	 * @param string   $label         section label.
	 * @param string[] $setting_ids   referenced setting ids.
	 * @param string   $description   optional description shown under the sub-tab.
	 * @param bool     $is_connection whether this section is a self-contained connection block.
	 * @param string   $action_label  label for the block's primary action button.
	 * @return self
	 */
	public static function create( string $id, string $label, array $setting_ids, string $description = '', bool $is_connection = false, string $action_label = '' ): self {
		return new self( $id, $label, $setting_ids, $description, $is_connection, $action_label );
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
}
