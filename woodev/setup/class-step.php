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
		$this->id                 = $id;
		$this->label              = $label;
		$this->type               = $type;
		$this->setting_ids        = [];
		$this->content            = null;
		$this->on_save            = null;
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

	/**
	 * Returns the step id.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns the step label.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Returns the step type.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Returns the referenced setting ids (settings steps only).
	 *
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	public function get_setting_ids(): array {
		return $this->setting_ids;
	}

	/**
	 * Returns the content callback or markup (content steps only).
	 *
	 * @since 2.0.2
	 *
	 * @return callable|string|null
	 */
	public function get_content() {
		return $this->content;
	}

	/**
	 * Returns the optional save side-effect callable.
	 *
	 * @since 2.0.2
	 *
	 * @return callable|null
	 */
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
