<?php
/**
 * Records engine — the activity read.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `POST R/activity`: the current state of the rows on screen, from local state only.
 *
 * Reading never claims a job, calls a marketplace or queues work — the engine passes the provider
 * nothing but the resolved context and the row ids, and rebuilds the answer from a whitelist
 * ({@see Row_Normalizer}, {@see Activity_Normalizer}). It is the provider's {@see Records_Provider::get_activity()}
 * that must stay a local read; the engine cannot enforce that, only keep the seam narrow.
 *
 * What a provider returns from `get_activity()`:
 *
 * - `rows` — the current rows (full row shape, with their `activity`) of the asked ids that still exist.
 *   An id that is neither returned nor removed is left as the browser has it;
 * - `removed_ids` — asked ids that are gone;
 * - `account_activity` — the account-wide state ({@see Activity_Normalizer::account()});
 * - `messages` — the same optional message map as the other reads.
 *
 * @since 2.1.0
 */
final class Activity_Reader {
	/**
	 * Validates the request body: a flat, distinct list of row ids within the table's `id_limit`.
	 *
	 * An empty list is allowed — an empty table still has an account line.
	 *
	 * @since 2.1.0
	 *
	 * @param Records_Definition $definition Definition (must declare `activity`).
	 * @param mixed              $ids        Raw `ids`.
	 * @return string[]|\WP_Error
	 */
	public static function ids( Records_Definition $definition, $ids ) {
		$declared = $definition->to_array()['activity'];
		if ( null === $declared ) {
			return Records_Error::not_found();
		}

		if ( ! is_array( $ids ) || array_values( $ids ) !== $ids ) {
			return Records_Error::invalid( 'ids' );
		}
		if ( count( $ids ) > $declared['id_limit'] ) {
			return Records_Error::too_large( 'woodev_records_activity_too_large', 'ids' );
		}

		foreach ( $ids as $id ) {
			if ( ! Query_Validator::is_row_id( $id ) ) {
				return Records_Error::invalid( 'ids' );
			}
		}

		return count( $ids ) === count( array_unique( $ids ) ) ? $ids : Records_Error::invalid( 'ids' );
	}

	/**
	 * Reads the activity of the asked rows and builds the response body.
	 *
	 * @since 2.1.0
	 *
	 * @param Records_Definition   $definition Definition (must declare `activity`).
	 * @param Records_Provider     $provider   Provider.
	 * @param array<string,string> $context    Resolved context.
	 * @param string[]             $ids        Ids that passed {@see self::ids()} and the provider's own check.
	 * @return array<string,mixed>|\WP_Error Body with `rows`, `removed_ids`, `account_activity`, `observed_at`,
	 *                                       `refresh_after_ms`, `messages`; a provider that breaks the contract is a 500.
	 */
	public static function read( Records_Definition $definition, Records_Provider $provider, array $context, array $ids ) {
		$result = $provider->get_activity( $context, $ids );

		try {
			$rows    = Row_Normalizer::rows( $definition, $result['rows'] ?? [], count( $ids ) );
			$removed = self::removed_ids( $ids, $result['removed_ids'] ?? [] );
			$account = Activity_Normalizer::account( $definition, $result['account_activity'] ?? null );

			self::check_rows_were_asked_for( $rows, $ids, $removed );

			return [
				'rows'             => $rows,
				'removed_ids'      => $removed,
				'account_activity' => $account,
				'observed_at'      => Activity_Normalizer::now(),
				'refresh_after_ms' => self::refresh_after( $definition, Activity_Normalizer::is_busy( $rows, $account ) ),
				'messages'         => Row_Normalizer::messages( $result['messages'] ?? [] ),
			];
		} catch ( \UnexpectedValueException $e ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}
	}

	/**
	 * How long the browser should wait before asking again: the table's cadence while work is going on,
	 * the slow ceiling when everything is quiet.
	 *
	 * @param Records_Definition $definition Definition (must declare `activity`).
	 * @param bool               $busy       Whether any work is still going on.
	 * @return int Milliseconds.
	 */
	private static function refresh_after( Records_Definition $definition, bool $busy ): int {
		$declared = $definition->to_array()['activity'];

		return $busy ? $declared['refresh_ms'] : $declared['max_refresh_ms'];
	}

	/**
	 * @param string[] $asked Ids the browser asked about.
	 * @param mixed    $raw   Provider `removed_ids`.
	 * @return string[]
	 */
	private static function removed_ids( array $asked, $raw ): array {
		if ( ! is_array( $raw ) || array_values( $raw ) !== $raw || count( $raw ) !== count( array_unique( $raw ) ) ) {
			throw new \UnexpectedValueException( 'removed_ids are a list of distinct ids.' );
		}
		if ( [] !== array_diff( $raw, $asked ) ) {
			throw new \UnexpectedValueException( 'removed_ids must be ids that were asked about.' );
		}

		return $raw;
	}

	/**
	 * A provider answers for the ids it was given — a row it was not asked about is a broken contract,
	 * and so is a row that is also reported removed.
	 *
	 * @param array<int,array<string,mixed>> $rows    Normalised rows.
	 * @param string[]                       $asked   Ids the browser asked about.
	 * @param string[]                       $removed Ids reported removed.
	 * @return void
	 */
	private static function check_rows_were_asked_for( array $rows, array $asked, array $removed ): void {
		$returned = array_column( $rows, 'id' );

		if ( [] !== array_diff( $returned, $asked ) || [] !== array_intersect( $returned, $removed ) ) {
			throw new \UnexpectedValueException( 'rows must be asked-for ids and not also removed.' );
		}
	}
}
