<?php
/**
 * Settings-page group descriptor.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One titled card inside an ordinary settings section: some of its fields, then some of its action
 * buttons in a single row.
 *
 * A group only NAMES its members by id — the fields stay in the section's own field map (so values,
 * validation, `show_if` and Save are untouched) and the actions stay in the section's own action list
 * (so the REST run route is untouched). Immutable: every `with_*()` returns a copy.
 *
 * @since 2.0.2
 */
final class Settings_Group {

	/** @var string group id, unique within its section. */
	private string $id;

	/** @var string card title. */
	private string $title;

	/** @var string optional text under the title (HTML, constrained at the schema boundary). */
	private string $description;

	/** @var string optional status line shown once under the group's buttons (plain text). */
	private string $notice = '';

	/** @var string[] ids of the section's fields this group holds, in declaration order. */
	private array $setting_ids = [];

	/** @var string[] ids of the section's actions this group holds, in declaration order. */
	private array $action_ids = [];

	/**
	 * Use {@see self::create()} instead.
	 *
	 * @since 2.0.2
	 */
	private function __construct( string $id, string $title, string $description ) {
		$this->id          = $id;
		$this->title       = $title;
		$this->description = $description;
	}

	/**
	 * Builds an empty group; add members with {@see self::with_fields()} and {@see self::with_actions()}.
	 *
	 * @since 2.0.2
	 *
	 * @param string $id          group id, unique within its section.
	 * @param string $title       card title.
	 * @param string $description optional text under the title.
	 * @return self
	 */
	public static function create( string $id, string $title, string $description = '' ): self {
		return new self( $id, $title, $description );
	}

	/**
	 * A copy that holds these of the section's fields. A field belongs to ONE group: when two groups name
	 * it, the first group declared keeps it.
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $setting_ids ids of fields declared by the section.
	 * @return self
	 */
	public function with_fields( array $setting_ids ): self {
		$copy              = clone $this;
		$copy->setting_ids = array_values( array_unique( array_map( 'strval', $setting_ids ) ) );

		return $copy;
	}

	/**
	 * A copy that holds these of the section's actions ({@see Settings_Section::with_actions()}), shown as
	 * buttons in one row with one shared result line.
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $action_ids ids of actions declared by the section.
	 * @return self
	 */
	public function with_actions( array $action_ids ): self {
		$copy             = clone $this;
		$copy->action_ids = array_values( array_unique( array_map( 'strval', $action_ids ) ) );

		return $copy;
	}

	/**
	 * A copy with a status line shown once under the group's buttons — «Сайт доступен только локально…»,
	 * say — instead of once per button.
	 *
	 * @since 2.0.2
	 *
	 * @param string $notice plain text.
	 * @return self
	 */
	public function with_notice( string $notice ): self {
		$copy         = clone $this;
		$copy->notice = $notice;

		return $copy;
	}

	/**
	 * @since 2.0.2
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * @since 2.0.2
	 * @return string
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * @since 2.0.2
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * @since 2.0.2
	 * @return string
	 */
	public function get_notice(): string {
		return $this->notice;
	}

	/**
	 * @since 2.0.2
	 * @return string[]
	 */
	public function get_setting_ids(): array {
		return $this->setting_ids;
	}

	/**
	 * @since 2.0.2
	 * @return string[]
	 */
	public function get_action_ids(): array {
		return $this->action_ids;
	}
}
