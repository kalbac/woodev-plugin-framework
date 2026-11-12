<?php
/**
 * One tab of a marketplace plugin's settings page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Settings\Settings_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Names a tab («Подключение», «Параметры», «Общие», …) and where its sections come from.
 *
 * The settings handler lists a tab for every `Settings_Tab` it was given ({@see Settings_Handler::get_settings_providers()}), in
 * order. The tab registers no settings of its own: the provider whose sections it shows registered them, so a field has one owner
 * and a tab is only a view over it.
 *
 * @since 2.1.5
 */
final class Settings_Tab implements Settings_Section_Provider {
	/**
	 * Short id; the tab id is built from it.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Localized label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Builds the tab's sections (`function (): Settings_Section[]`).
	 *
	 * @var callable
	 */
	private $sections;

	/**
	 * Whether the tab draws its sections stacked under headings instead of as sub-tabs.
	 *
	 * @var bool
	 */
	private $stacked;

	/**
	 * Creates the tab.
	 *
	 * @since 2.1.5
	 *
	 * @param string   $slug     Short id (`connection`, `parameters`, …).
	 * @param string   $label    Localized label.
	 * @param callable $sections `function (): Settings_Section[]`, called when the page or a route needs the sections.
	 * @param bool     $stacked  True to draw every section of the tab under its own heading, one after another, with no sub-tabs
	 *                           ({@see Settings_Section::stacked()}).
	 */
	public function __construct( string $slug, string $label, callable $sections, bool $stacked = false ) {
		$this->slug     = $slug;
		$this->label    = $label;
		$this->sections = $sections;
		$this->stacked  = $stacked;
	}

	/**
	 * Short id of the tab.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Label of the tab.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * A tab owns no settings.
	 *
	 * @since 2.1.5
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
	}

	/**
	 * The tab's sections.
	 *
	 * @since 2.1.5
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array {
		$sections = array_values( (array) call_user_func( $this->sections ) );

		if ( ! $this->stacked ) {
			return $sections;
		}

		return array_map(
			static function ( Settings_Section $section ): Settings_Section {
				return $section->is_stacked() ? $section : $section->stacked();
			},
			$sections
		);
	}
}
