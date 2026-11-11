<?php
/**
 * Records engine — validation of an action's input fields.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the `payload` of a preview or apply request against the fields the action declares.
 *
 * Two kinds of mistake, two answers:
 *
 * - a key the action does not declare is a malformed request → 400, like any unknown parameter;
 * - a declared field with a wrong or missing value → 422 with every offending field id and a
 *   sentence for it, so the dialog can mark each one at once.
 *
 * A value that the engine can check only by shape — an option of a local source such as a
 * warehouse — is passed on after the shape check; the provider refuses an unknown one with
 * {@see Action_Input_Exception}. Nothing is coerced into something the caller did not send, and
 * a field that was not sent is not in the result (an omitted boolean is not `false`).
 *
 * @since 2.1.0
 */
final class Action_Input {

	/** Longest single-line text. */
	const MAX_TEXT_LENGTH = 500;

	/** Longest multi-line text. */
	const MAX_TEXTAREA_LENGTH = 2000;

	/**
	 * Validates a payload.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string,mixed> $action  Normalised action descriptor.
	 * @param mixed               $payload Raw `payload` from the request body.
	 * @return array<string,mixed>|\WP_Error Typed values by field id; empty when the action has no fields.
	 */
	public static function validate( array $action, $payload ) {
		if ( ! is_array( $payload ) || ( [] !== $payload && array_values( $payload ) === $payload ) ) {
			return Records_Error::invalid( 'payload' );
		}

		$fields = array_column( $action['fields'], null, 'id' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! isset( $fields[ (string) $key ] ) ) {
				return Records_Error::invalid( 'payload.' . $key );
			}
		}

		$typed  = [];
		$errors = [];

		foreach ( $action['fields'] as $field ) {
			if ( ! array_key_exists( $field['id'], $payload ) ) {
				self::missing( $field, $errors );
				continue;
			}

			try {
				$typed[ $field['id'] ] = self::value( $field, $payload[ $field['id'] ] );
			} catch ( \InvalidArgumentException $e ) {
				$errors[ $field['id'] ] = $e->getMessage();
			}
		}

		return [] === $errors ? $typed : Action_Error::fields( $errors );
	}

	/**
	 * A field that was not sent is an error only when it is required.
	 *
	 * @param array<string,mixed>  $field  Field descriptor.
	 * @param array<string,string> $errors Errors collected so far (by reference).
	 * @return void
	 */
	private static function missing( array $field, array &$errors ): void {
		if ( $field['required'] ) {
			$errors[ $field['id'] ] = self::required_message();
		}
	}

	/**
	 * @param array<string,mixed> $field Field descriptor.
	 * @param mixed               $raw   Raw value.
	 * @return mixed Typed value.
	 * @throws \InvalidArgumentException With the merchant's sentence.
	 */
	private static function value( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'text':
				return self::text( $field, $raw, self::MAX_TEXT_LENGTH, false );
			case 'textarea':
				return self::text( $field, $raw, self::MAX_TEXTAREA_LENGTH, true );
			case 'number':
				return self::number( $raw );
			case 'boolean':
				return self::boolean( $raw );
			case 'select':
			case 'multi_select':
				return self::choice( $field, $raw );
		}

		throw new \InvalidArgumentException( self::invalid_message() );
	}

	/**
	 * @param array<string,mixed> $field     Field descriptor.
	 * @param mixed               $raw       Raw value.
	 * @param int                 $max       Longest text.
	 * @param bool                $multiline Whether line breaks are allowed.
	 * @return string
	 * @throws \InvalidArgumentException With the merchant's sentence.
	 */
	private static function text( array $field, $raw, int $max, bool $multiline ): string {
		if ( ! is_string( $raw ) ) {
			throw new \InvalidArgumentException( self::invalid_message() );
		}

		$text = trim( $raw );
		if ( '' === $text && $field['required'] ) {
			throw new \InvalidArgumentException( self::required_message() );
		}
		if ( mb_strlen( $text ) > $max ) {
			throw new \InvalidArgumentException( __( 'Значение слишком длинное.', 'woodev-plugin-framework' ) );
		}

		$forbidden = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
		if ( 1 === preg_match( $forbidden, $text ) ) {
			throw new \InvalidArgumentException( __( 'Значение содержит недопустимые символы.', 'woodev-plugin-framework' ) );
		}

		return $text;
	}

	/**
	 * A JSON number, or a plain decimal string a form would send. Never `NaN` or an exponent.
	 *
	 * @param mixed $raw Raw value.
	 * @return int|float
	 * @throws \InvalidArgumentException With the merchant's sentence.
	 */
	private static function number( $raw ) {
		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_float( $raw ) && is_finite( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && 1 === preg_match( '/^-?\d{1,15}(\.\d{1,6})?$/D', trim( $raw ) ) ) {
			$number = trim( $raw );

			return false === strpos( $number, '.' ) ? (int) $number : (float) $number;
		}

		throw new \InvalidArgumentException( __( 'Введите число.', 'woodev-plugin-framework' ) );
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return bool
	 * @throws \InvalidArgumentException With the merchant's sentence.
	 */
	private static function boolean( $raw ): bool {
		if ( ! is_bool( $raw ) ) {
			throw new \InvalidArgumentException( self::invalid_message() );
		}

		return $raw;
	}

	/**
	 * `select` and `multi_select` follow the rules of the same filter types: inline options are a
	 * closed set, a source is checked by shape only and left to the provider.
	 *
	 * @param array<string,mixed> $field Field descriptor.
	 * @param mixed               $raw   Raw value.
	 * @return string|string[]
	 * @throws \InvalidArgumentException With the merchant's sentence.
	 */
	private static function choice( array $field, $raw ) {
		$empty = null === $raw || '' === $raw || [] === $raw;
		if ( $empty && $field['required'] ) {
			throw new \InvalidArgumentException( self::required_message() );
		}
		if ( $empty ) {
			return 'multi_select' === $field['type'] ? [] : '';
		}

		$typed = Query_Validator::normalize_filter_value( $field, $raw );
		if ( null === $typed ) {
			throw new \InvalidArgumentException( __( 'Выберите значение из списка.', 'woodev-plugin-framework' ) );
		}

		return $typed;
	}

	/**
	 * @return string
	 */
	private static function required_message(): string {
		return __( 'Заполните это поле.', 'woodev-plugin-framework' );
	}

	/**
	 * @return string
	 */
	private static function invalid_message(): string {
		return __( 'Некорректное значение.', 'woodev-plugin-framework' );
	}
}
