<?php
/**
 * Row ids of the page of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * A shipment row is `{account id}:{shipment key}`, a unit row under it `{account id}:{shipment key}:{unit key}`. The keys are the
 * hashes the store finds them by.
 *
 * @since 2.1.28
 */
final class Shipments_Ids {
	private const PATTERN = '/^([1-9][0-9]{0,18}):([a-f0-9]{32})(?::([a-f0-9]{32}))?$/D';

	/**
	 * @since 2.1.28
	 *
	 * @param int    $account_id Account.
	 * @param string $key        Shipment key.
	 * @param string $unit_key   Unit key; empty for the shipment's own row.
	 * @return string
	 */
	public static function row( int $account_id, string $key, string $unit_key = '' ): string {
		return $account_id . ':' . $key . ( '' === $unit_key ? '' : ':' . $unit_key );
	}

	/**
	 * Reads a row id of an account.
	 *
	 * @since 2.1.28
	 *
	 * @param string $id         Row id.
	 * @param int    $account_id Account the table is open for.
	 * @return array{shipment:string,unit:string}|null Null when it is malformed or names another account.
	 */
	public static function parse( string $id, int $account_id ): ?array {
		if ( 1 !== preg_match( self::PATTERN, $id, $found ) || (int) $found[1] !== $account_id ) {
			return null;
		}

		return [
			'shipment' => $found[2],
			'unit'     => $found[3] ?? '',
		];
	}
}
