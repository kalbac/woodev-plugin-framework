<?php
/**
 * The authenticated label download.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Flows\Label_Access_Exception;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Brain\Monkey\Functions;

/**
 * Moved from the Ozon plugin's `LabelsTest` (the download half): anonymous, nonce, capability, document, account, licence, and
 * every order of the document owned by the account and editable by the user. What «owned» means is the adapter's order meta.
 */
final class LabelDownloadTest extends Label_Test_Case {
	/**
	 * The stamp the test account puts on its orders (its `cabinet_ref` credential).
	 *
	 * @var string
	 */
	private const OWNER = 'cabinet-4417';

	/**
	 * Anonymous request to the download URL → 403 (the nonce and capability are never reached).
	 *
	 * @return void
	 */
	public function test_an_anonymous_download_is_403(): void {
		$document_id = $this->document( [ '0001-1' ] );

		$this->assertDenied(
			403,
			$this->download(
				[
					'logged_in' => false,
					'nonce'     => true,
					'can'       => true,
				]
			),
			$document_id
		);
	}

	/**
	 * A user with `edit_shop_orders` who may not edit one order of the document → 403; the same user allowed on all → the body.
	 *
	 * @return void
	 */
	public function test_a_user_who_may_not_edit_one_order_is_403(): void {
		$document_id = $this->document( [ '0001-1', '0002-1' ] );
		$orders      = [
			'0001-1' => 11,
			'0002-1' => 12,
		];

		$allowed_one = $this->download(
			[
				'orders' => $orders,
				'can'    => [
					'edit_shop_orders' => true,
					'edit_shop_order'  => [ 11 ],
				],
			]
		);
		$this->assertDenied( 403, $allowed_one, $document_id );

		$allowed_all = $this->download(
			[
				'orders' => $orders,
				'can'    => [
					'edit_shop_orders' => true,
					'edit_shop_order'  => [ 11, 12 ],
				],
			]
		);
		$this->assertSame( self::PDF, $allowed_all->authorise( $document_id )['body'] );
	}

	/**
	 * The user without `edit_shop_orders`, a wrong nonce, an order of another account, an order with no WooCommerce order, a lapsed
	 * licence, a deleted account and a document that is gone are all refused.
	 *
	 * @return void
	 */
	public function test_every_other_check_refuses(): void {
		$orders      = [ '0001-1' => 11 ];
		$document_id = $this->document( [ '0001-1' ] );
		$may         = [
			'edit_shop_orders' => true,
			'edit_shop_order'  => [ 11 ],
		];

		$this->assertDenied( 403, $this->download( [ 'orders' => $orders, 'can' => [ 'edit_shop_orders' => false ] ] ), $document_id, 'no capability' );
		$this->assertDenied( 403, $this->download( [ 'orders' => $orders, 'can' => $may, 'nonce' => false ] ), $document_id, 'bad nonce' );
		$this->assertDenied( 403, $this->download( [ 'orders' => $orders, 'can' => $may, 'owner' => 'cabinet-999' ] ), $document_id, 'order of another account' );
		$this->assertDenied( 403, $this->download( [ 'orders' => [], 'can' => $may ] ), $document_id, 'order with no WooCommerce order' );
		$this->assertDenied( 404, $this->download( [ 'orders' => $orders, 'can' => $may ] ), $document_id + 50, 'document is gone' );
		$this->assertDenied( 403, $this->download( [ 'orders' => $orders, 'can' => $may, 'licensed' => false ] ), $document_id, 'licence' );

		$foreign = $this->labels->add_document( 4242, 1, [ '0001-1' ], 'application/pdf', self::PDF );
		$this->assertDenied( 403, $this->download( [ 'orders' => $orders, 'can' => $may ] ), $foreign, 'account that does not exist' );

		$this->assertSame( self::PDF, $this->download( [ 'orders' => $orders, 'can' => $may ] )->authorise( $document_id )['body'] );
	}

	/**
	 * A document with no orders has nobody whose permission could be checked; an order that carries no owner stamp is not the
	 * account's.
	 *
	 * @return void
	 */
	public function test_a_document_without_orders_or_an_order_without_a_stamp_is_refused(): void {
		$may = [
			'edit_shop_orders' => true,
			'edit_shop_order'  => [ 11 ],
		];

		$this->assertDenied( 403, $this->download( [ 'orders' => [ '0001-1' => 11 ], 'can' => $may ] ), $this->document( [] ), 'no orders' );
		$this->assertDenied( 403, $this->download( [ 'orders' => [ '0001-1' => 11 ], 'can' => $may, 'owner' => '' ] ), $this->document( [ '0001-1' ] ), 'order without a stamp' );
	}

	/**
	 * The nonce action is per document, so a link for one document does not open another.
	 *
	 * @return void
	 */
	public function test_the_nonce_action_is_per_document(): void {
		$download = $this->download( [] );

		$this->assertNotSame( $download->nonce_action( 1 ), $download->nonce_action( 2 ) );
		$this->assertSame( 'woodev_fake_seller_label_1', $download->nonce_action( 1 ) );
	}

	/**
	 * The action, the hooks and the address are named after the plugin.
	 *
	 * @return void
	 */
	public function test_the_action_and_the_url_are_named_after_the_plugin(): void {
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( string $url, string $action ): string {
				return $url . '&_wpnonce=' . $action;
			}
		);

		$download = $this->download( [] );

		$this->assertSame( 'woodev_fake_seller_label', $download->action() );
		$this->assertSame(
			'https://shop.test/wp-admin/admin-post.php?action=woodev_fake_seller_label&document_id=7&_wpnonce=woodev_fake_seller_label_7',
			$download->url( 7 )
		);
	}

	/**
	 * Asserts that the download refuses with a status.
	 *
	 * @param int            $status      Expected status.
	 * @param Label_Download $download    Handler.
	 * @param int            $document_id Document id.
	 * @param string         $why         Message.
	 * @return void
	 */
	private function assertDenied( int $status, Label_Download $download, int $document_id, string $why = '' ): void {
		try {
			$download->authorise( $document_id );
			$this->fail( 'The download must be refused. ' . $why );
		} catch ( Label_Access_Exception $e ) {
			$this->assertSame( $status, $e->getCode(), $why );
		}
	}

	/**
	 * Builds a download handler with doubles. Options: `logged_in`, `nonce`, `licensed` (bool), `orders` (remote key → order id),
	 * `owner` (stamp on every order), `can` (true/false for every check, or capability → bool|order ids).
	 *
	 * @param array<string,mixed> $options Options.
	 * @return Label_Download
	 */
	private function download( array $options ): Label_Download {
		$orders = (array) ( $options['orders'] ?? [] );
		$can    = $options['can'] ?? true;
		$meta   = new Fake_Order_Meta();

		foreach ( $orders as $order_id ) {
			$meta->owners[ $order_id ] = (string) ( $options['owner'] ?? self::OWNER );
		}

		return new Label_Download(
			$this->labels,
			$this->accounts(),
			static function ( int $account_id, string $key ) use ( $orders ): ?int {
				return $orders[ $key ] ?? null;
			},
			$meta,
			new License_Gate(
				static function () use ( $options ): bool {
					return $options['licensed'] ?? true;
				}
			),
			$this->names(),
			'fake',
			static function ( string $capability, ...$args ) use ( $can ): bool {
				if ( is_bool( $can ) ) {
					return $can;
				}

				$rule = $can[ $capability ] ?? false;

				return is_array( $rule ) ? in_array( $args[0] ?? 0, $rule, true ) : (bool) $rule;
			},
			static function () use ( $options ): bool {
				return $options['nonce'] ?? true;
			},
			static function () use ( $options ): bool {
				return $options['logged_in'] ?? true;
			}
		);
	}
}
