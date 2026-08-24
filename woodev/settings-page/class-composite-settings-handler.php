<?php
/**
 * Composite settings handler.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One settings handler over several `Woodev_Abstract_Settings` children.
 *
 * `Settings_Provider` binds ONE handler per tab; the «Доставка» tab shows sections owned by
 * three handlers (location / checkout fields / pickup map), each keeping its own option
 * namespace. This class routes every call `Field_Schema` and the settings REST controller make
 * to the child that registered the setting id. It deliberately implements neither
 * `Woodev_Settings_Connection_Status` (no child needs it yet) nor a MULTI-child
 * `Woodev_Settings_Connection_Test` router — {@see self::test_connection()} delegates to
 * the single child that implements it (#488 D8: `Location_Settings` is the first real
 * connection-test consumer this class ever had), and throws rather than guessing when
 * zero or more than one child does.
 *
 * `get_value()` / `update_value()` throw `\Woodev_Plugin_Exception` on an unknown id, mirroring
 * `Woodev_Abstract_Settings` exactly, so this class is behaviourally substitutable for a real
 * handler (the REST save path already expects and handles that throw — see
 * class-rest-api-settings-page.php's try/catch around `update_value()`, and its
 * try/catch(\Woodev_Plugin_Exception) around `get_value()`).
 *
 * `filter_visible_values()` resolves `show_if` conditions across the whole tab, so a field may
 * depend on a controller owned by a sibling handler. It intentionally diverges from the base
 * class for an id no child owns: that id is DROPPED here, whereas
 * `Woodev_Abstract_Settings::filter_visible_values()` passes it through unchanged. Harmless in
 * practice — the REST controller already scopes the submitted values to the tab's declared
 * setting ids via `array_intersect_key()` before calling this — but worth knowing if this class
 * is ever reused somewhere without that pre-filtering.
 *
 * @since 2.0.2
 */
final class Composite_Settings_Handler implements \Woodev_Settings_Connection_Test {

	/** @var string */
	private string $id;

	/** @var \Woodev_Abstract_Settings[] setting id => owning child. */
	private array $owner_by_id = [];

	/** @var \Woodev_Abstract_Settings[] */
	private array $children;

	/**
	 * @since 2.0.2
	 * @param string                      $id       tab-level id (NOT an option namespace — children own those).
	 * @param \Woodev_Abstract_Settings[] $children handlers, in section order.
	 * @throws \InvalidArgumentException when two children register the same setting id.
	 */
	public function __construct( string $id, array $children ) {
		$this->id       = $id;
		$this->children = array_values( $children );

		foreach ( $this->children as $child ) {
			foreach ( $child->get_settings() as $setting ) {
				$sid = $setting->get_id();
				if ( isset( $this->owner_by_id[ $sid ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Setting id "%s" is registered by two handlers.', $sid ) );
				}
				$this->owner_by_id[ $sid ] = $child;
			}
		}
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
	 * @since 2.0.2 When `$ids` is given, the result follows THAT order rather than child
	 *              order — a `Settings_Section` interleaving fields from two handlers gets
	 *              the order it declared.
	 * @param string[] $ids optional filter, in the desired display order.
	 * @return \Woodev_Setting[] keyed by id, in `$ids` order when given, else child order.
	 */
	public function get_settings( array $ids = [] ): array {
		$collected = [];

		foreach ( $this->children as $child ) {
			foreach ( $child->get_settings( $ids ) as $sid => $setting ) {
				$collected[ $sid ] = $setting;
			}
		}

		if ( empty( $ids ) ) {
			return $collected;
		}

		/*
		 * Child-major order is wrong for a section that interleaves handlers. The
		 * «Поля» section lists `region_field` (checkout handler) and the region field-type
		 * axis (location handler) next to each other on purpose — each axis belongs beside
		 * the field it governs — and collecting child by child pulled every location-owned
		 * setting above every checkout-owned one, so the axes rendered detached from their
		 * fields. Reorder to the caller's list; ids no child owns simply never appear,
		 * which is this class's documented divergence.
		 */
		$ordered = [];

		foreach ( $ids as $id ) {
			$id = (string) $id;

			if ( isset( $collected[ $id ] ) ) {
				$ordered[ $id ] = $collected[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Delegates to the single child that implements `Woodev_Settings_Connection_Test`
	 * (#488 D8).
	 *
	 * There is no id->child map because nothing needs one yet: exactly one child
	 * (`Location_Settings`, as of #488) implements the interface at a time. Throws
	 * rather than guessing when zero or more than one child does — a REST request
	 * only ever reaches this method for a `$connection_id` the tab's own
	 * `Settings_Section::is_connection()` list already proved exists (see
	 * class-rest-api-settings-page.php's `test_connection()`), so ambiguity here
	 * means a NEW child started implementing the interface without this class
	 * being taught how to route between them — extend with an explicit
	 * id-to-child map when that day comes.
	 *
	 * @since 2.0.2
	 *
	 * @param string              $connection_id connection section id.
	 * @param array<string,mixed> $values        merged field values (POSTed ∪ stored).
	 * @return \Woodev_Connection_Result
	 * @throws \Woodev_Plugin_Exception When no child, or more than one, implements
	 *                                   `Woodev_Settings_Connection_Test`.
	 */
	public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
		$delegate = null;

		foreach ( $this->children as $child ) {
			if ( $child instanceof \Woodev_Settings_Connection_Test ) {
				if ( null !== $delegate ) {
					throw new \Woodev_Plugin_Exception( 'More than one child handler implements Woodev_Settings_Connection_Test; Composite_Settings_Handler::test_connection() needs an explicit id-to-child map to disambiguate.' );
				}
				$delegate = $child;
			}
		}

		if ( null === $delegate ) {
			throw new \Woodev_Plugin_Exception( "No child handler implements Woodev_Settings_Connection_Test for connection \"{$connection_id}\"." );
		}

		return $delegate->test_connection( $connection_id, $values );
	}

	/**
	 * @since 2.0.2
	 * @param string $id setting id.
	 * @return \Woodev_Setting|null
	 */
	public function get_setting( string $id ) {
		return isset( $this->owner_by_id[ $id ] ) ? $this->owner_by_id[ $id ]->get_setting( $id ) : null;
	}

	/**
	 * @since 2.0.2
	 * @param string $id           setting id.
	 * @param bool   $with_default whether to return the default value if nothing is stored.
	 * @return mixed
	 * @throws \Woodev_Plugin_Exception when no child registered this id.
	 */
	public function get_value( string $id, bool $with_default = true ) {
		if ( ! isset( $this->owner_by_id[ $id ] ) ) {
			throw new \Woodev_Plugin_Exception( "Setting {$id} does not exist" );
		}
		return $this->owner_by_id[ $id ]->get_value( $id, $with_default );
	}

	/**
	 * @since 2.0.2
	 * @param string $id    setting id.
	 * @param mixed  $value new value.
	 * @throws \Woodev_Plugin_Exception when no child registered this id.
	 */
	public function update_value( string $id, $value ) {
		if ( ! isset( $this->owner_by_id[ $id ] ) ) {
			throw new \Woodev_Plugin_Exception( "Setting {$id} does not exist", 404 );
		}
		$this->owner_by_id[ $id ]->update_value( $id, $value );
	}

	/**
	 * @since 2.0.2
	 * @param array<string,mixed> $values
	 * @return array<string,string> setting id => error message.
	 */
	public function validate_values( array $values ): array {
		$errors = [];
		foreach ( $this->split_by_owner( $values ) as $i => $chunk ) {
			$errors += $this->children[ $i ]->validate_values( $chunk );
		}
		return $errors;
	}

	/**
	 * @since 2.0.2
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public function filter_visible_values( array $values ): array {
		// Resolve every field against the ORIGINAL tab-level submitted map before
		// stripping. A condition may name a setting owned by a sibling handler;
		// splitting first would make that controller look unregistered and turn it
		// into the empty string.
		$hidden = [];

		foreach ( array_keys( $values ) as $setting_id ) {
			$setting = $this->get_setting( (string) $setting_id );

			if ( null === $setting ) {
				$hidden[] = $setting_id;
				continue;
			}

			$conditions = $setting->get_show_if_conditions();

			if ( empty( $conditions ) ) {
				continue;
			}

			if ( ! \Woodev_Setting::evaluate_conditions( $conditions, $this->effective_condition_values( $conditions, $values ) ) ) {
				$hidden[] = $setting_id;
			}
		}

		foreach ( $hidden as $setting_id ) {
			unset( $values[ $setting_id ] );
		}

		return $values;
	}

	/**
	 * Builds the tab-wide controlling-value map a condition group needs.
	 *
	 * A submitted controller wins. Otherwise, a controller registered by any
	 * child resolves through the owning child; an id no child owns is the empty
	 * string, matching Woodev_Abstract_Settings::effective_condition_values().
	 *
	 * @since 2.0.2
	 * @param array<string,mixed> $conditions show_if condition group.
	 * @param array<string,mixed> $submitted  submitted setting id => value.
	 * @return array<string,mixed> controlling setting id => effective value.
	 */
	private function effective_condition_values( array $conditions, array $submitted ): array {
		$group  = isset( $conditions['setting'] ) ? [ $conditions ] : $conditions;
		$result = [];

		foreach ( $group as $condition ) {
			if ( ! is_array( $condition ) || ! isset( $condition['setting'] ) ) {
				continue;
			}

			$id            = (string) $condition['setting'];
			$result[ $id ] = array_key_exists( $id, $submitted )
				? $submitted[ $id ]
				: ( null !== $this->get_setting( $id ) ? $this->get_value( $id ) : '' );
		}

		return $result;
	}

	/**
	 * Splits a submitted map into per-child chunks (unknown ids are dropped).
	 *
	 * @param array<string,mixed> $values
	 * @return array<int,array<string,mixed>> child index => values.
	 */
	private function split_by_owner( array $values ): array {
		$chunks = [];
		foreach ( $values as $sid => $value ) {
			if ( ! isset( $this->owner_by_id[ $sid ] ) ) {
				continue;
			}
			$i                   = array_search( $this->owner_by_id[ $sid ], $this->children, true );
			$chunks[ $i ][ $sid ] = $value;
		}
		return $chunks;
	}
}
