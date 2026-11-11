<?php
/**
 * Records engine — REST errors of the action routes.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * The two answers only the action routes give, next to the ones in {@see Records_Error}:
 * 422 for input the action's fields refuse, and 409 for a known, authorised action that the
 * current state refuses.
 *
 * Both carry sentences that reach the merchant, so both pass the provider's text through
 * {@see wp_strip_all_tags()} and a length bound first.
 *
 * @since 2.0.30
 */
final class Action_Error {

	/** Longest sentence kept from a provider. */
	const MAX_LENGTH = 500;

	/**
	 * 422: the input does not fit the action's fields. The body names every offending field, so
	 * the dialog can mark each one; the message is for a toast above the form.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,string> $errors Field id => sentence.
	 * @return \WP_Error
	 */
	public static function fields( array $errors ): \WP_Error {
		return new \WP_Error(
			'woodev_records_invalid_fields',
			__( 'Проверьте заполненные поля.', 'woodev-plugin-framework' ),
			[
				'status' => 422,
				'errors' => $errors,
			]
		);
	}

	/**
	 * 422 from a provider's {@see Action_Input_Exception}. Only declared fields may carry an error;
	 * anything else is the provider breaking the contract.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,string>           $errors Field id => sentence, as the provider sent it.
	 * @param array<int,array<string,mixed>> $fields The action's declared fields.
	 * @return \WP_Error
	 */
	public static function from_provider( array $errors, array $fields ): \WP_Error {
		$declared = array_column( $fields, 'id' );
		$clean    = [];

		foreach ( $errors as $field => $sentence ) {
			if ( ! in_array( $field, $declared, true ) || ! is_string( $sentence ) ) {
				return Records_Error::internal( 'woodev_records_invalid_provider_response' );
			}
			$clean[ $field ] = self::sentence( $sentence );
		}

		return [] === $clean ? Records_Error::internal( 'woodev_records_invalid_provider_response' ) : self::fields( $clean );
	}

	/**
	 * 409: the action exists and the user may run it, but the current state refuses it. The
	 * reason is the provider's own sentence.
	 *
	 * @since 2.0.30
	 *
	 * @param string $reason Provider sentence; a generic one is used when it is empty.
	 * @return \WP_Error
	 */
	public static function conflict( string $reason ): \WP_Error {
		$reason = self::sentence( $reason );

		return new \WP_Error(
			'woodev_records_action_conflict',
			'' === $reason ? __( 'Сейчас это действие недоступно.', 'woodev-plugin-framework' ) : $reason,
			[ 'status' => 409 ]
		);
	}

	/**
	 * @param string $text Provider text.
	 * @return string Tags stripped, trimmed, bounded.
	 */
	private static function sentence( string $text ): string {
		return mb_substr( trim( wp_strip_all_tags( $text ) ), 0, self::MAX_LENGTH );
	}
}
