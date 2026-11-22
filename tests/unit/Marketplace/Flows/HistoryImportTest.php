<?php
/**
 * The history import keeps WooCommerce's e-mails back (#1925).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\History_Import;
use Woodev\Tests\Unit\TestCase;

/**
 * WooCommerce decides whether an e-mail goes out with `woocommerce_email_enabled_{id}`, asked by every e-mail class when its
 * trigger runs. The test keeps a tiny filter table behind `add_filter` / `remove_filter` / `apply_filters`, and a mailer with two
 * e-mails whose trigger asks that filter the way `WC_Email::is_enabled()` does and «sends» only when it answers yes.
 *
 * Every test runs in its own process: the mailer is reached through `WC()`, and a Brain Monkey `when( 'WC' )` defines that function
 * for the rest of the process, which would flip every `function_exists( 'WC' )` guard of the suite for the classes that run after
 * this one (gotcha `brain-monkey-function-pollution`).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class HistoryImportTest extends TestCase {
	/**
	 * Callbacks per filter name: `[ callback, priority ]`.
	 *
	 * @var array<string,array<int,array{0:callable,1:int}>>
	 */
	private $filters = [];

	/**
	 * Ids of the e-mails WooCommerce «sent».
	 *
	 * @var string[]
	 */
	private $sent = [];

	/**
	 * Installs the filter table and the mailer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->filters = [];
		$this->sent    = [];

		Functions\when( 'add_filter' )->alias(
			function ( string $name, $callback, int $priority = 10 ): bool {
				$this->filters[ $name ][] = [ $callback, $priority ];

				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $name, $callback, int $priority = 10 ): bool {
				foreach ( $this->filters[ $name ] ?? [] as $index => $registered ) {
					if ( $registered === [ $callback, $priority ] ) {
						unset( $this->filters[ $name ][ $index ] );

						return true;
					}
				}

				return false;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( string $name, $value ) {
				foreach ( $this->filters[ $name ] ?? [] as $registered ) {
					$value = call_user_func( $registered[0], $value );
				}

				return $value;
			}
		);

		$emails = [ (object) [ 'id' => 'new_order' ], (object) [ 'id' => 'cancelled_order' ] ];
		$mailer = new class( $emails ) {
			/**
			 * The e-mails.
			 *
			 * @var object[]
			 */
			private $emails;

			/**
			 * @param object[] $emails The e-mails.
			 */
			public function __construct( array $emails ) {
				$this->emails = $emails;
			}

			/**
			 * @return object[]
			 */
			public function get_emails(): array {
				return $this->emails;
			}
		};

		Functions\when( 'WC' )->justReturn(
			new class( $mailer ) {
				/**
				 * The mailer.
				 *
				 * @var object
				 */
				private $mailer;

				/**
				 * @param object $mailer The mailer.
				 */
				public function __construct( $mailer ) {
					$this->mailer = $mailer;
				}

				/**
				 * @return object
				 */
				public function mailer() {
					return $this->mailer;
				}
			}
		);
	}

	/**
	 * What WooCommerce does when an order is created and then changes status: every e-mail asks its own switch, and sends when it
	 * is on.
	 *
	 * @return void
	 */
	public function woocommerce_mails_the_order(): void {
		foreach ( [ 'new_order', 'cancelled_order' ] as $id ) {
			if ( apply_filters( 'woocommerce_email_enabled_' . $id, true ) ) {
				$this->sent[] = $id;
			}
		}
	}

	/**
	 * A save made while history is written sends nothing, and says what its callable returned.
	 *
	 * @return void
	 */
	public function test_a_history_write_sends_no_woocommerce_email(): void {
		$result = History_Import::during(
			function () {
				return History_Import::quietly(
					function () {
						$this->woocommerce_mails_the_order();

						return 'saved';
					}
				);
			}
		);

		$this->assertSame( 'saved', $result );
		$this->assertSame( [], $this->sent );
	}

	/**
	 * A save outside history sends its mails, before the import and after it, and a history write leaves no switch behind.
	 *
	 * @return void
	 */
	public function test_a_write_that_is_not_history_still_mails_before_and_after_the_import(): void {
		History_Import::quietly( [ $this, 'woocommerce_mails_the_order' ] );
		$this->assertSame( [ 'new_order', 'cancelled_order' ], $this->sent, 'before the import' );

		$this->sent = [];
		History_Import::during(
			function () {
				History_Import::quietly( [ $this, 'woocommerce_mails_the_order' ] );
			}
		);
		$this->assertSame( [], $this->sent, 'the import itself' );
		$this->assertSame( [], array_filter( $this->filters ), 'no switch is left registered' );

		History_Import::quietly( [ $this, 'woocommerce_mails_the_order' ] );
		$this->assertSame( [ 'new_order', 'cancelled_order' ], $this->sent, 'after the import' );
	}

	/**
	 * An order WooCommerce mails inside the import's stretch but outside a store save — a checkout in the same cron tick — is not
	 * the import's write: only the store's own save is quiet.
	 *
	 * @return void
	 */
	public function test_only_the_stores_own_save_is_quiet(): void {
		History_Import::during(
			function () {
				$this->woocommerce_mails_the_order();
			}
		);

		$this->assertSame( [ 'new_order', 'cancelled_order' ], $this->sent );
	}

	/**
	 * A save that throws puts the e-mails back, closes the import's stretch, and lets the exception through.
	 *
	 * @return void
	 */
	public function test_the_emails_come_back_when_the_write_throws(): void {
		try {
			History_Import::during(
				function () {
					History_Import::quietly(
						static function (): void {
							throw new \RuntimeException( 'the save failed' );
						}
					);
				}
			);
			$this->fail( 'The exception must reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the save failed', $e->getMessage() );
		}

		$this->assertFalse( History_Import::is_active(), 'the stretch is closed' );
		$this->assertSame( [], array_filter( $this->filters ), 'the switches are removed' );

		History_Import::quietly( [ $this, 'woocommerce_mails_the_order' ] );
		$this->assertSame( [ 'new_order', 'cancelled_order' ], $this->sent );
	}

	/**
	 * A save inside a save keeps the outer one's switch until the outer one ends.
	 *
	 * @return void
	 */
	public function test_a_nested_save_does_not_turn_the_emails_back_on_early(): void {
		History_Import::during(
			function () {
				History_Import::quietly(
					function () {
						History_Import::quietly( [ $this, 'woocommerce_mails_the_order' ] );
						$this->woocommerce_mails_the_order();
					}
				);
			}
		);

		$this->assertSame( [], $this->sent );
		$this->assertSame( [], array_filter( $this->filters ) );
	}
}
