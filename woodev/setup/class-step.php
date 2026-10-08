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

	/** @var string optional step description shown in the wizard UI. */
	private string $description = '';

	/** @var callable|null visibility predicate. */
	private $visibility_callback;

	/** @var bool whether the merchant may move past the step without saving it. */
	private bool $skippable = true;

	/** @var callable|null server-side validation, run before anything is persisted. */
	private $validation_callback;

	/** @var array<string,Step_Action> actions bound to the step, keyed by id. */
	private array $actions = [];

	/**
	 * Use the named constructors instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $label, string $type ) {
		$this->id = $id;
		$this->label = $label;
		$this->type = $type;
		$this->setting_ids = [];
		$this->content = null;
		$this->on_save = null;
		$this->visibility_callback = null;
		$this->validation_callback = null;
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
	 * @param string        $description optional step description.
	 * @return self
	 */
	public static function settings( string $id, string $label, array $setting_ids, ?callable $on_save = null, string $description = '' ): self {
		$step              = new self( $id, $label, self::TYPE_SETTINGS );
		$step->setting_ids = array_values( $setting_ids );
		$step->on_save     = $on_save;
		$step->description = $description;

		return $step;
	}

	/**
	 * Builds a content step.
	 *
	 * @since 2.0.2
	 *
	 * @param string          $id          step id.
	 * @param string          $label       step label.
	 * @param callable|string $content     content callback or markup.
	 * @param string          $description optional step description.
	 * @return self
	 */
	public static function content( string $id, string $label, $content, string $description = '' ): self {
		$step              = new self( $id, $label, self::TYPE_CONTENT );
		$step->content     = $content;
		$step->description = $description;

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
	 * Returns the step description.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
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

		try {
			return (bool) call_user_func( $this->visibility_callback );
		} catch ( \Throwable $e ) {
			// The predicate is the plugin's own code and runs while the wizard is being
			// built — an Error here must not take the admin down. A step whose predicate
			// cannot answer is hidden (never saved, never shown) and the failure is logged.
			Callback_Failure::log( sprintf( 'visibility check failed for step "%s"', $this->id ), $e );

			return false;
		}
	}

	/**
	 * Marks the step as mandatory (or optional again) — fluent.
	 *
	 * A non-skippable step has no «Пропустить» control in the client. The wizard keeps no
	 * per-step progress on the server, so this is a client-side contract: it removes the
	 * control, it does not make the server refuse a hand-crafted request.
	 *
	 * @since 2.0.2
	 *
	 * @param bool $skippable whether the merchant may skip the step (default true).
	 * @return self
	 */
	public function set_skippable( bool $skippable = true ): self {
		$this->skippable = $skippable;

		return $this;
	}

	/**
	 * Whether the merchant may skip the step. Defaults to true.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_skippable(): bool {
		return $this->skippable;
	}

	/**
	 * Sets the server-side validation callback (fluent).
	 *
	 * Signature: `fn( array $values, \WP_REST_Request $request ): array|bool|null`. It runs
	 * BEFORE anything of the step is persisted, with the EFFECTIVE values of the fields
	 * declared on the step — the merchant's edits over the stored (else default) values, i.e.
	 * what the step shows, minus fields hidden by their show_if (only edited fields are
	 * persisted afterwards). A content step may have one too: it gets an empty map and
	 * decides from the plugin's own state; Continue is refused until it passes. Return a map of `field id => message` to refuse the save (nothing
	 * is persisted, `on_save` does not run, the client shows each message on its field), or
	 * `false` to refuse with a generic message; `null`, `true` or an empty array mean valid.
	 * A throw is an unexpected failure: logged, answered with a generic message, nothing persisted.
	 *
	 * @since 2.0.2
	 *
	 * @param callable $callback the validator.
	 * @return self
	 */
	public function set_validation_callback( callable $callback ): self {
		$this->validation_callback = $callback;

		return $this;
	}

	/**
	 * Returns the validation callback.
	 *
	 * @since 2.0.2
	 *
	 * @return callable|null
	 */
	public function get_validation_callback(): ?callable {
		return $this->validation_callback;
	}

	/**
	 * Binds an action to the step (fluent). A second action with the same id replaces the first.
	 *
	 * @since 2.0.2
	 *
	 * @param Step_Action $action the action.
	 * @return self
	 */
	public function add_action( Step_Action $action ): self {
		$this->actions[ $action->get_id() ] = $action;

		return $this;
	}

	/**
	 * Returns the step's actions keyed by id.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,Step_Action>
	 */
	public function get_actions(): array {
		return $this->actions;
	}

	/**
	 * Returns one action by id.
	 *
	 * @since 2.0.2
	 *
	 * @param string $action_id action id.
	 * @return Step_Action|null
	 */
	public function get_action( string $action_id ): ?Step_Action {
		return $this->actions[ $action_id ] ?? null;
	}
}
