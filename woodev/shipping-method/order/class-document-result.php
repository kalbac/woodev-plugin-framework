<?php
/** Carrier document lookup result. */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Result returned by a carrier document source.
 *
 * @since 2.0.2
 */
final class Document_Result {
	public const READY_BINARY = 'ready_binary';
	public const READY_URL    = 'ready_url';
	public const PENDING      = 'pending';
	public const FAILED       = 'failed';

	private string $state;
	private string $value;
	private int $retry_after;

	private function __construct( string $state, string $value = '', int $retry_after = 0 ) {
		$this->state       = $state;
		$this->value       = $value;
		$this->retry_after = max( 0, $retry_after );
	}

	/** @since 2.0.2 @param string $bytes PDF bytes @return self */
	public static function binary( string $bytes ): self {
		return new self( self::READY_BINARY, $bytes ); }

	/** @since 2.0.2 @param string $url direct carrier URL @return self */
	public static function url( string $url ): self {
		return new self( self::READY_URL, $url ); }

	/** @since 2.0.2 @param int $retry_after seconds before retry @return self */
	public static function pending( int $retry_after = 5 ): self {
		return new self( self::PENDING, '', $retry_after ); }

	/** @since 2.0.2 @param string $reason failure reason @return self */
	public static function failed( string $reason ): self {
		return new self( self::FAILED, $reason ); }

	/** @since 2.0.2 @return string */
	public function get_state(): string {
		return $this->state; }

	/** @since 2.0.2 @return string */
	public function get_value(): string {
		return $this->value; }

	/** @since 2.0.2 @return int */
	public function get_retry_after(): int {
		return $this->retry_after; }
}
