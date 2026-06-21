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

	/**
	 * Returns the wizard id (delegates to the owning plugin).
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->plugin->get_id();
	}

	/**
	 * Returns the owning plugin instance.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Plugin
	 */
	public function get_plugin(): \Woodev_Plugin {
		return $this->plugin;
	}

	/**
	 * Returns the registered (visible) steps keyed by id.
	 *
	 * @since 2.0.2
	 *
	 * @return Step[]
	 */
	public function get_steps(): array {
		return $this->steps;
	}

	/**
	 * Whether the wizard has any visible steps.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function has_steps(): bool {
		return ! empty( $this->steps );
	}

	/**
	 * Returns the required WordPress capability for accessing the wizard.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_required_capability(): string {
		return $this->required_capability;
	}
}
