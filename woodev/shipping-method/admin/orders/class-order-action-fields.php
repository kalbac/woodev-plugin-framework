<?php
/**
 * Shipping orders — the input fields an extra order action may declare
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Action_Fields' ) ) :

	/**
	 * The small, typed input schema an extra order action may declare (card #1180) and the server-side
	 * check of what a merchant typed against it.
	 *
	 * A carrier's extra action (`woodev_shipping_order_actions`) that needs a few values before it can
	 * run — «Вызвать курьера» wants a day, a time window and an optional comment — declares them under
	 * its `fields` key. The orders page and the order metabox then ask for them in a dialog and send them
	 * as the action's `payload`; {@see self::validate()} checks the payload against the declaration
	 * BEFORE the carrier's handler is called, so a handler only ever sees values that fit.
	 *
	 * Deliberately NOT a form engine: four types — exactly what the courier call needs — and nothing a
	 * type does not own (no conditional visibility, no groups, no custom validators). A fifth type is
	 * added here, in both {@see self::sanitize()} and {@see self::validate()}, and in the two UIs.
	 *
	 * | type         | declared keys (besides id/label/required/default)  | payload value                 |
	 * |--------------|-----------------------------------------------------|-------------------------------|
	 * | `date`       | `min`, `max` — `Y-m-d`                              | `'Y-m-d'`                     |
	 * | `select`     | `options` — list of `[ value, label ]`              | one of the option values      |
	 * | `time_range` | `min`, `max` — `H:i`                                | `[ 'from' => 'H:i', 'to' => 'H:i' ]` |
	 * | `textarea`   | `maxlength`                                         | string                        |
	 *
	 * @since 2.0.2
	 */
	final class Order_Action_Fields {

		/** @var string */
		public const TYPE_DATE = 'date';

		/** @var string */
		public const TYPE_SELECT = 'select';

		/** @var string */
		public const TYPE_TIME_RANGE = 'time_range';

		/** @var string */
		public const TYPE_TEXTAREA = 'textarea';

		/**
		 * The longest a textarea may be when its declaration states no `maxlength` — an unbounded
		 * free-text value reaching a carrier API is never what a plugin author meant.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const DEFAULT_TEXTAREA_MAXLENGTH = 1000;

		/**
		 * The ceiling a declared `maxlength` is clamped to.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_TEXTAREA_MAXLENGTH = 5000;

		/** Not instantiable — a namespace of two pure functions. */
		private function __construct() {}

		/**
		 * Re-validates a declared `fields` list, dropping malformed entries rather than shipping them to
		 * the client — the same stance {@see Order_Actions::sanitize_actions()} takes for the action
		 * itself.
		 *
		 * A field is dropped when it has no usable `id` (lowercase letters, digits, `_`, `-`), repeats an
		 * earlier id, has no `label`, has an unknown `type`, or is a `select` without a single usable
		 * option. Every kept field has the same keys for its type, so the client never branches on a
		 * missing one.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $fields the declared value, of unknown shape.
		 * @return array<int,array<string,mixed>> the field list; `[]` when nothing usable was declared.
		 */
		public static function sanitize( $fields ): array {
			if ( ! is_array( $fields ) ) {
				return [];
			}

			$sanitized = [];
			$seen      = [];

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$id    = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '';
				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';
				$type  = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';

				if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) || isset( $seen[ $id ] ) || '' === $label ) {
					continue;
				}

				$clean = [
					'id'       => $id,
					'type'     => $type,
					'label'    => $label,
					'required' => (bool) ( $field['required'] ?? false ),
				];

				switch ( $type ) {
					case self::TYPE_DATE:
						$clean['default'] = self::valid_date( $field['default'] ?? null ) ? (string) $field['default'] : '';

						foreach ( [ 'min', 'max' ] as $bound ) {
							if ( self::valid_date( $field[ $bound ] ?? null ) ) {
								$clean[ $bound ] = (string) $field[ $bound ];
							}
						}
						break;

					case self::TYPE_SELECT:
						$options = self::sanitize_options( $field['options'] ?? null );

						if ( [] === $options ) {
							continue 2;
						}

						$values           = array_column( $options, 'value' );
						$clean['options'] = $options;
						$clean['default'] = isset( $field['default'] ) && is_string( $field['default'] ) && in_array( $field['default'], $values, true )
							? $field['default']
							: '';
						break;

					case self::TYPE_TIME_RANGE:
						$default          = is_array( $field['default'] ?? null ) ? $field['default'] : [];
						$from             = $default['from'] ?? null;
						$to               = $default['to'] ?? null;
						$clean['default'] = self::valid_time( $from ) && self::valid_time( $to )
							? [
								'from' => (string) $from,
								'to'   => (string) $to,
							]
							: [
								'from' => '',
								'to'   => '',
							];

						foreach ( [ 'min', 'max' ] as $bound ) {
							if ( self::valid_time( $field[ $bound ] ?? null ) ) {
								$clean[ $bound ] = (string) $field[ $bound ];
							}
						}
						break;

					case self::TYPE_TEXTAREA:
						$maxlength          = isset( $field['maxlength'] ) && is_numeric( $field['maxlength'] ) ? (int) $field['maxlength'] : self::DEFAULT_TEXTAREA_MAXLENGTH;
						$clean['maxlength'] = max( 1, min( self::MAX_TEXTAREA_MAXLENGTH, $maxlength ) );
						$clean['default']   = isset( $field['default'] ) && is_string( $field['default'] )
							? mb_substr( $field['default'], 0, $clean['maxlength'] )
							: '';
						break;

					default:
						continue 2;
				}

				$seen[ $id ] = true;
				$sanitized[] = $clean;
			}

			return $sanitized;
		}

		/**
		 * Checks a payload against a SANITISED field list ({@see self::sanitize()}) and returns what the
		 * action's handler may use.
		 *
		 * Only declared ids come out — a key the declaration does not name is dropped, never forwarded.
		 * Every declared id is present in `values`: an empty optional field is `''` (a `time_range`
		 * `[ 'from' => '', 'to' => '' ]`), so a handler never tests `isset()`. Text is trimmed and
		 * stripped of markup; nothing is truncated — a value that is too long is an error, not a silent
		 * cut.
		 *
		 * `errors` is `[ { field, code, message } ]`, the shape the order wizard's routes answer
		 * (`Order_Editor_Controller`), with `field` the declared id. Codes: `required`, `invalid`,
		 * `out_of_range` (outside `min` / `max`), `invalid_option`, `invalid_range` (a window that does
		 * not end after it starts), `too_long`. One error per field.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $fields  the sanitised field list.
		 * @param mixed                          $payload what the client sent, of unknown shape.
		 * @return array{values: array<string,mixed>, errors: array<int,array{field:string,code:string,message:string}>}
		 */
		public static function validate( array $fields, $payload ): array {
			$payload = is_array( $payload ) ? $payload : [];
			$values  = [];
			$errors  = [];

			foreach ( $fields as $field ) {
				$id    = (string) $field['id'];
				$raw   = $payload[ $id ] ?? null;
				$error = null;

				switch ( $field['type'] ) {
					case self::TYPE_DATE:
						[ $values[ $id ], $error ] = self::check_date( $field, $raw );
						break;

					case self::TYPE_SELECT:
						[ $values[ $id ], $error ] = self::check_select( $field, $raw );
						break;

					case self::TYPE_TIME_RANGE:
						[ $values[ $id ], $error ] = self::check_time_range( $field, $raw );
						break;

					default:
						[ $values[ $id ], $error ] = self::check_textarea( $field, $raw );
						break;
				}

				if ( null !== $error ) {
					$errors[] = [
						'field'   => $id,
						'code'    => $error,
						'message' => self::error_message( $error, $field ),
					];
				}
			}

			return [
				'values' => $values,
				'errors' => $errors,
			];
		}

		/**
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $field the sanitised `date` field.
		 * @param mixed               $raw   the posted value.
		 * @return array{0: string, 1: string|null} value and error code.
		 */
		private static function check_date( array $field, $raw ): array {
			$value = is_string( $raw ) ? trim( $raw ) : '';

			if ( '' === $value ) {
				return [ '', ! empty( $field['required'] ) ? 'required' : null ];
			}

			if ( ! self::valid_date( $value ) ) {
				return [ '', 'invalid' ];
			}

			if ( ( isset( $field['min'] ) && $value < $field['min'] ) || ( isset( $field['max'] ) && $value > $field['max'] ) ) {
				return [ '', 'out_of_range' ];
			}

			return [ $value, null ];
		}

		/**
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $field the sanitised `select` field.
		 * @param mixed               $raw   the posted value.
		 * @return array{0: string, 1: string|null} value and error code.
		 */
		private static function check_select( array $field, $raw ): array {
			$value = is_string( $raw ) || is_int( $raw ) ? trim( (string) $raw ) : '';

			if ( '' === $value ) {
				return [ '', ! empty( $field['required'] ) ? 'required' : null ];
			}

			if ( ! in_array( $value, array_column( $field['options'], 'value' ), true ) ) {
				return [ '', 'invalid_option' ];
			}

			return [ $value, null ];
		}

		/**
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $field the sanitised `time_range` field.
		 * @param mixed               $raw   the posted value.
		 * @return array{0: array{from:string,to:string}, 1: string|null} value and error code.
		 */
		private static function check_time_range( array $field, $raw ): array {
			$empty = [
				'from' => '',
				'to'   => '',
			];
			$from  = is_array( $raw ) && isset( $raw['from'] ) && is_string( $raw['from'] ) ? trim( $raw['from'] ) : '';
			$to    = is_array( $raw ) && isset( $raw['to'] ) && is_string( $raw['to'] ) ? trim( $raw['to'] ) : '';

			if ( '' === $from && '' === $to ) {
				return [ $empty, ! empty( $field['required'] ) ? 'required' : null ];
			}

			if ( ! self::valid_time( $from ) || ! self::valid_time( $to ) ) {
				return [ $empty, 'invalid' ];
			}

			if ( $from >= $to ) {
				return [ $empty, 'invalid_range' ];
			}

			if ( ( isset( $field['min'] ) && $from < $field['min'] ) || ( isset( $field['max'] ) && $to > $field['max'] ) ) {
				return [ $empty, 'out_of_range' ];
			}

			return [
				[
					'from' => $from,
					'to'   => $to,
				],
				null,
			];
		}

		/**
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $field the sanitised `textarea` field.
		 * @param mixed               $raw   the posted value.
		 * @return array{0: string, 1: string|null} value and error code.
		 */
		private static function check_textarea( array $field, $raw ): array {
			$value = is_string( $raw ) ? trim( sanitize_textarea_field( $raw ) ) : '';

			if ( '' === $value ) {
				return [ '', ! empty( $field['required'] ) ? 'required' : null ];
			}

			if ( mb_strlen( $value ) > (int) $field['maxlength'] ) {
				return [ $value, 'too_long' ];
			}

			return [ $value, null ];
		}

		/**
		 * The merchant-facing sentence for one error code.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $code  the error code.
		 * @param array<string,mixed> $field the sanitised field the error is about.
		 * @return string
		 */
		private static function error_message( string $code, array $field ): string {
			switch ( $code ) {
				case 'required':
					return __( 'Заполните это поле.', 'woodev-plugin-framework' );

				case 'out_of_range':
					return __( 'Значение вне допустимых пределов.', 'woodev-plugin-framework' );

				case 'invalid_option':
					return __( 'Выберите значение из списка.', 'woodev-plugin-framework' );

				case 'invalid_range':
					return __( 'Время окончания должно быть позже времени начала.', 'woodev-plugin-framework' );

				case 'too_long':
					return sprintf(
						/* translators: %d: the longest the text may be, in characters. */
						__( 'Не больше %d символов.', 'woodev-plugin-framework' ),
						(int) $field['maxlength']
					);

				default:
					return __( 'Проверьте значение.', 'woodev-plugin-framework' );
			}
		}

		/**
		 * Keeps the usable options of a `select`, accepting a list of `[ value, label ]` or a plain
		 * `value => label` map. A value is a non-empty string (an integer key is cast), unique, and
		 * a label falls back to the value.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $options the declared options.
		 * @return array<int,array{value:string,label:string}>
		 */
		private static function sanitize_options( $options ): array {
			if ( ! is_array( $options ) ) {
				return [];
			}

			$clean = [];
			$seen  = [];

			foreach ( $options as $key => $option ) {
				if ( is_array( $option ) ) {
					$value = $option['value'] ?? null;
					$label = $option['label'] ?? $value;
				} else {
					$value = $key;
					$label = $option;
				}

				if ( ! is_string( $value ) && ! is_int( $value ) ) {
					continue;
				}

				$value = (string) $value;
				$label = is_string( $label ) || is_int( $label ) ? trim( (string) $label ) : '';

				if ( '' === $value || isset( $seen[ $value ] ) ) {
					continue;
				}

				$seen[ $value ] = true;
				$clean[]        = [
					'value' => $value,
					'label' => '' !== $label ? $label : $value,
				];
			}

			return $clean;
		}

		/**
		 * Whether a value is a real calendar date written `Y-m-d`.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the value.
		 * @return bool
		 */
		private static function valid_date( $value ): bool {
			if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
				return false;
			}

			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		}

		/**
		 * Whether a value is a time of day written `H:i` (00:00–23:59).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the value.
		 * @return bool
		 */
		private static function valid_time( $value ): bool {
			return is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
		}
	}

endif;
