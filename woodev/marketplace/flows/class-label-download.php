<?php
/**
 * The authenticated label download.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action={plugin id}_label&document_id=…&_wpnonce=…` (spec 5.5 step 5). The body of the document is streamed by
 * PHP only after every check of spec 5.5 step 5 and 8.5 has passed:
 *
 * 1. a logged-in user (the `nopriv` hook is registered on purpose so an anonymous visitor gets a 403, not WordPress's 400);
 * 2. the nonce of this document (`check_admin_referer`);
 * 3. `edit_shop_orders`;
 * 4. the document exists, and its account exists;
 * 5. the licence is in force;
 * 6. for **every** order of the document: a WooCommerce order is registered for it, that order belongs to the document's
 *    account (the owner stamp it carries equals the account's, both from the adapter's {@see Order_Meta_Contract}), and the user
 *    may `edit_shop_order` it. An order with no WooCommerce order stops the download: there is nobody whose permission could be
 *    checked.
 *
 * @since 2.1.0
 */
final class Label_Download {
	/**
	 * Store.
	 *
	 * @var Wpdb_Label_Store
	 */
	private $store;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Returns the WooCommerce order id of a remote order: `function ( int $account_id, string $external_key ): ?int`.
	 *
	 * @var callable
	 */
	private $order_of;

	/**
	 * The marketplace's order meta.
	 *
	 * @var Order_Meta_Contract
	 */
	private $meta;

	/**
	 * Licence gate.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Hook names.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Marketplace id the downloaded file is named after.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Capability check: `function ( string $capability, ...$args ): bool`.
	 *
	 * @var callable
	 */
	private $can;

	/**
	 * Nonce check: `function ( string $action ): bool`.
	 *
	 * @var callable
	 */
	private $nonce_ok;

	/**
	 * Login check: `function (): bool`.
	 *
	 * @var callable
	 */
	private $logged_in;

	/**
	 * Creates the handler. The last three defaults are WordPress's own functions; tests pass doubles.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Label_Store    $store      Store.
	 * @param Wpdb_Account_Store  $accounts   Accounts.
	 * @param callable            $order_of   `function ( int $account_id, string $external_key ): ?int`.
	 * @param Order_Meta_Contract $meta       The marketplace's order meta.
	 * @param License_Gate        $license    Licence gate.
	 * @param Job_Names           $names      Hook names of the plugin.
	 * @param string              $adapter_id The marketplace's id (the adapter's `id()`), for the file name.
	 * @param callable|null       $can        `function ( string $capability, ...$args ): bool`; defaults to `current_user_can`.
	 * @param callable|null       $nonce_ok   `function ( string $action ): bool`; defaults to `check_admin_referer`.
	 * @param callable|null       $logged_in  `function (): bool`; defaults to `is_user_logged_in`.
	 */
	public function __construct( Wpdb_Label_Store $store, Wpdb_Account_Store $accounts, callable $order_of, Order_Meta_Contract $meta, License_Gate $license, Job_Names $names, string $adapter_id, ?callable $can = null, ?callable $nonce_ok = null, ?callable $logged_in = null ) {
		$this->store      = $store;
		$this->accounts   = $accounts;
		$this->order_of   = $order_of;
		$this->meta       = $meta;
		$this->license    = $license;
		$this->names      = $names;
		$this->adapter_id = $adapter_id;
		$this->can        = $can ?? 'current_user_can';
		$this->nonce_ok   = $nonce_ok ?? static function ( string $action ): bool {
			return false !== check_admin_referer( $action );
		};
		$this->logged_in  = $logged_in ?? 'is_user_logged_in';
	}

	/**
	 * Returns the `admin-post.php` action of the download.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->names->hook( 'label' );
	}

	/**
	 * Hooks the handler, for logged-in users and, to answer them with a 403, for anyone else.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . $this->action(), [ $this, 'handle' ] );
		add_action( 'admin_post_nopriv_' . $this->action(), [ $this, 'handle' ] );
	}

	/**
	 * Builds the download address of a document, with its nonce.
	 *
	 * @since 2.1.0
	 *
	 * @param int $document_id Document id.
	 * @return string
	 */
	public function url( int $document_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'      => $this->action(),
					'document_id' => $document_id,
				],
				admin_url( 'admin-post.php' )
			),
			$this->nonce_action( $document_id )
		);
	}

	/**
	 * Returns the nonce action of a document.
	 *
	 * @since 2.1.0
	 *
	 * @param int $document_id Document id.
	 * @return string
	 */
	public function nonce_action( int $document_id ): string {
		return $this->action() . '_' . $document_id;
	}

	/**
	 * Answers the request: streams the document or ends with the status of the refusal.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function handle(): void {
		$document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorise() checks the nonce first.

		try {
			$document = $this->authorise( $document_id );
		} catch ( Label_Access_Exception $e ) {
			wp_die( esc_html( $e->getMessage() ), '', [ 'response' => (int) $e->getCode() ] );
		}

		$this->stream( $document );
	}

	/**
	 * Runs every check and returns the document.
	 *
	 * @since 2.1.0
	 *
	 * @param int $document_id Document id from the request.
	 * @return array{document_id:int,account_id:int,task_id:int,external_keys:string[],content_type:string,body:string}
	 * @throws Label_Access_Exception With the status to answer: 403 for every refusal, 404 for a document that is not there.
	 */
	public function authorise( int $document_id ): array {
		if ( true !== call_user_func( $this->logged_in ) ) {
			throw $this->forbidden(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( $document_id < 1 || true !== call_user_func( $this->nonce_ok, $this->nonce_action( $document_id ) ) || ! $this->may( 'edit_shop_orders' ) ) {
			throw $this->forbidden(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$document = $this->store->document( $document_id );

		if ( null === $document ) {
			throw Label_Access_Exception::with_status( 404, __( 'Этикетки не найдены: возможно, прошло больше 7 дней. Создайте их заново.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$account = $this->accounts->get( $document['account_id'] );

		if ( null === $account ) {
			throw $this->forbidden(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$refusal = $this->license->refusal_for( __( 'скачать этикетки', 'woodev-plugin-framework' ) );

		if ( null !== $refusal ) {
			throw Label_Access_Exception::with_status( 403, $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->require_every_order( $document, $account );

		return $document;
	}

	/**
	 * Requires the user to own and be allowed to edit every order of the document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param Account             $account  Account of the document.
	 * @return void
	 * @throws Label_Access_Exception On the first order that fails a check.
	 */
	private function require_every_order( array $document, Account $account ): void {
		$keys  = $document['external_keys'];
		$owner = $this->meta->account_owner( $account );

		if ( [] === $keys || '' === $owner ) {
			throw $this->forbidden(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		foreach ( $keys as $key ) {
			$order_id = call_user_func( $this->order_of, $account->id(), (string) $key );

			if ( ! is_int( $order_id ) || $order_id < 1 || $this->meta->order_owner( $order_id ) !== $owner || ! $this->may( 'edit_shop_order', $order_id ) ) {
				throw $this->forbidden(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}
		}
	}

	/**
	 * Asks the capability check.
	 *
	 * @param string $capability Capability.
	 * @param mixed  ...$args    Object arguments.
	 * @return bool
	 */
	private function may( string $capability, ...$args ): bool {
		return true === call_user_func( $this->can, $capability, ...$args );
	}

	/**
	 * Builds the refusal that says nothing about why.
	 *
	 * @return Label_Access_Exception
	 */
	private function forbidden(): Label_Access_Exception {
		return Label_Access_Exception::with_status( 403, __( 'У вас нет прав скачать эти этикетки.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Sends the body with headers that keep it out of caches and make the browser treat it as the type it is.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return void
	 */
	private function stream( array $document ): void {
		$type = (string) $document['content_type'];
		$ext  = false !== strpos( $type, 'pdf' ) ? 'pdf' : ( false !== strpos( $type, 'json' ) ? 'json' : 'bin' );

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $this->adapter_id ) . '-labels-' . (int) $document['document_id'] . '.' . $ext . '"' );
		header( 'Content-Length: ' . strlen( (string) $document['body'] ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $document['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A binary document, not markup.
		exit;
	}
}
