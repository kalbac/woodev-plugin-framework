<?php
/**
 * A minimal stub of WooCommerce's `WC_Session_Handler` for unit tests (#1381).
 *
 * The real class ships with WooCommerce and is absent from the framework's unit suite.
 * This stub carries exactly what `Session_Key_Merger` reads: the exact class name, the
 * `has_session()` / `get_customer_id()` answers, the protected `$_dirty` flag the merge reads
 * by reflection, and `get()` / `set()` with the REAL WooCommerce 11.2.1 semantics
 * (`abstract-wc-session.php`): values are kept serialized per key, `set( $key, null )`
 * removes the key, and setting an equal value changes nothing and does not dirty the
 * session. Guarded so it never collides with a real definition — and only ever required
 * from a `@runInSeparateProcess` test, because a class declared in the shared PHPUnit
 * process cannot be undeclared.
 *
 * `maybe_serialize()` / `maybe_unserialize()` are expected from the test (Brain Monkey
 * stubs), as they are WordPress functions.
 *
 * @package Woodev\Tests\Unit
 */

if ( ! class_exists( 'WC_Session_Handler', false ) ) {

	class WC_Session_Handler {

		/** @var array<string, mixed> per-key values, each `maybe_serialize()`d as WooCommerce keeps them. */
		protected $_data = []; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

		/** @var bool */
		protected $_dirty = false; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

		/** @var string */
		private string $customer_id;

		/** @var bool */
		private bool $has_session;

		/**
		 * @param string               $customer_id What `get_customer_id()` answers.
		 * @param array<string, mixed> $loaded      The session as this request loaded it, plain values.
		 * @param bool                 $has_session What `has_session()` answers.
		 */
		public function __construct( string $customer_id = 't_guest', array $loaded = [], bool $has_session = true ) {
			$this->customer_id = $customer_id;
			$this->has_session = $has_session;

			foreach ( $loaded as $key => $value ) {
				$this->_data[ $key ] = maybe_serialize( $value );
			}
		}

		/** @return string */
		public function get_customer_id() {
			return $this->customer_id;
		}

		/** @return bool */
		public function has_session() {
			return $this->has_session;
		}

		/**
		 * @param string $key           Session key.
		 * @param mixed  $default_value Fallback when absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default_value = null ) {
			return isset( $this->_data[ $key ] ) ? maybe_unserialize( $this->_data[ $key ] ) : $default_value;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value; `null` removes the key.
		 *
		 * @return void
		 */
		public function set( $key, $value ) {
			if ( null === $value ) {
				if ( isset( $this->_data[ $key ] ) ) {
					unset( $this->_data[ $key ] );
					$this->_dirty = true;
				}

				return;
			}

			$serialized_original_value = $this->_data[ $key ] ?? null;
			$serialized_value          = maybe_serialize( $value );

			if ( $serialized_original_value === $serialized_value || maybe_unserialize( $serialized_original_value ) === $value ) {
				return;
			}

			$this->_dirty        = true;
			$this->_data[ $key ] = $serialized_value;
		}

		/**
		 * Test accessor: the raw per-key data, as `save_data()` would serialize it.
		 *
		 * @return array<string, mixed>
		 */
		public function raw_data(): array {
			return $this->_data;
		}

		/**
		 * Test accessor: WooCommerce's own «will save» flag.
		 *
		 * @return bool
		 */
		public function is_dirty(): bool {
			return $this->_dirty;
		}
	}
}
