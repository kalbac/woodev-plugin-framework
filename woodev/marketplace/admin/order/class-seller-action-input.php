<?php
/**
 * The shape check of what the merchant typed into a seller action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Action_Input;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the declared fields out of a request, with the same limits and sentences as the «Заказы» table's own check
 * ({@see Action_Input}), so a dialog says the same on both screens. A {@see Seller_Action_Field::CODE} is passed on as typed:
 * a marking code holds characters a text clean-up would remove.
 *
 * @since 2.1.27
 */
final class Seller_Action_Input {
	/**
	 * Control characters a code must not hold: all of them but the group separator U+001D.
	 */
	private const CODE_FORBIDDEN = '/[\x00-\x1C\x1E\x1F\x7F]/';

	/**
	 * Reads the values of an action's fields.
	 *
	 * @since 2.1.27
	 *
	 * @param Seller_Action       $action Declared action.
	 * @param array<string,mixed> $raw    Values by field id, unslashed. An undeclared key is ignored.
	 * @return array<string,string> Values by field id; an empty field is left out.
	 * @throws Action_Input_Exception With a sentence per refused field.
	 */
	public static function read( Seller_Action $action, array $raw ): array {
		$values = [];
		$errors = [];

		foreach ( $action->fields() as $field ) {
			$value = isset( $raw[ $field->id() ] ) && is_string( $raw[ $field->id() ] ) ? self::clean( $field, $raw[ $field->id() ] ) : '';
			$error = self::error( $field, $value );

			if ( '' !== $error ) {
				$errors[ $field->id() ] = $error;
			} elseif ( '' !== $value ) {
				$values[ $field->id() ] = $value;
			}
		}

		if ( [] !== $errors ) {
			throw new Action_Input_Exception( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $values;
	}

	/**
	 * @param Seller_Action_Field $field Field.
	 * @param string              $value Raw value.
	 * @return string
	 */
	private static function clean( Seller_Action_Field $field, string $value ): string {
		switch ( $field->type() ) {
			case Seller_Action_Field::TEXT:
				return sanitize_text_field( $value );
			case Seller_Action_Field::TEXTAREA:
				return sanitize_textarea_field( $value );
			default:
				return trim( $value ); // A code and a list value are not rewritten; trim() keeps the group separator.
		}
	}

	/**
	 * @param Seller_Action_Field $field Field.
	 * @param string              $value Cleaned value.
	 * @return string The sentence for the merchant; empty when the value is acceptable.
	 */
	private static function error( Seller_Action_Field $field, string $value ): string {
		if ( '' === $value ) {
			return $field->is_required() ? __( 'Заполните это поле.', 'woodev-plugin-framework' ) : '';
		}

		$limit = Seller_Action_Field::TEXTAREA === $field->type() ? Action_Input::MAX_TEXTAREA_LENGTH : Action_Input::MAX_TEXT_LENGTH;

		if ( mb_strlen( $value ) > $limit ) {
			return __( 'Значение слишком длинное.', 'woodev-plugin-framework' );
		}

		if ( Seller_Action_Field::CODE === $field->type() && 1 === preg_match( self::CODE_FORBIDDEN, $value ) ) {
			return __( 'Значение содержит недопустимые символы.', 'woodev-plugin-framework' );
		}

		if ( Seller_Action_Field::SELECT === $field->type() && ! self::is_choice( $field, $value ) ) {
			return __( 'Выберите значение из списка.', 'woodev-plugin-framework' );
		}

		return '';
	}

	/**
	 * A fixed list is a closed set; a list from the adapter is checked by shape and left to the adapter.
	 *
	 * @param Seller_Action_Field $field Field.
	 * @param string              $value Value.
	 * @return bool
	 */
	private static function is_choice( Seller_Action_Field $field, string $value ): bool {
		if ( $field->has_options_source() ) {
			return 1 === preg_match( Records_Definition::VALUE_PATTERN, $value );
		}

		return in_array( $value, array_column( $field->options(), 'value' ), true );
	}
}
