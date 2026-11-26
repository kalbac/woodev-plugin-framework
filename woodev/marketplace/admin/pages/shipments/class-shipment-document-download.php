<?php
/**
 * The download of a shipment's document.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Shipments\Shipment_File;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action={plugin id}_shipment_document&account_id=…&shipment=…&document=…&_wpnonce=…`. The document is read from
 * the marketplace when it is downloaded, and streamed only after every check: a logged-in user, the nonce of this document,
 * `edit_shop_orders`, an account of the marketplace, and the rules of {@see Shipment_Service::document()} (account switched on,
 * licence in force, the document available). Nothing of it is stored in the shop.
 *
 * @since 2.1.28
 */
final class Shipment_Document_Download {
	/**
	 * @var Shipment_Service
	 */
	private $service;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Job_Names
	 */
	private $names;

	/**
	 * The marketplace's id: the accounts it serves.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment_Service   $service    The documents.
	 * @param Wpdb_Account_Store $accounts   Accounts.
	 * @param Job_Names          $names      Hook names of the plugin.
	 * @param string             $adapter_id The marketplace's id.
	 */
	public function __construct( Shipment_Service $service, Wpdb_Account_Store $accounts, Job_Names $names, string $adapter_id ) {
		$this->service    = $service;
		$this->accounts   = $accounts;
		$this->names      = $names;
		$this->adapter_id = $adapter_id;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The `admin-post.php` action.
	 */
	public function action(): string {
		return $this->names->hook( 'shipment_document' );
	}

	/**
	 * Hooks the handler, for logged-in users and, to answer them with a 403, for anyone else.
	 *
	 * @since 2.1.28
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
	 * @since 2.1.28
	 *
	 * @param int    $account_id  Account.
	 * @param string $key         Shipment key.
	 * @param string $document_id Document id.
	 * @return string
	 */
	public function url( int $account_id, string $key, string $document_id ): string {
		$address = add_query_arg(
			[
				'action'     => $this->action(),
				'account_id' => $account_id,
				'shipment'   => $key,
				'document'   => $document_id,
			],
			admin_url( 'admin-post.php' )
		);

		// `wp_nonce_url()` escapes the address for HTML; the browser gets it in JSON.
		return add_query_arg( '_wpnonce', wp_create_nonce( $this->nonce_action( $account_id, $key, $document_id ) ), $address );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param int    $account_id  Account.
	 * @param string $key         Shipment key.
	 * @param string $document_id Document id.
	 * @return string The nonce action of a document.
	 */
	public function nonce_action( int $account_id, string $key, string $document_id ): string {
		return $this->action() . '_' . $account_id . '_' . $key . '_' . $document_id;
	}

	/**
	 * Answers the request: streams the document, or ends with the status of the refusal.
	 *
	 * @since 2.1.28
	 *
	 * @return void
	 */
	public function handle(): void {
		$account_id  = isset( $_GET['account_id'] ) ? absint( wp_unslash( $_GET['account_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorise() checks the nonce first.
		$key         = isset( $_GET['shipment'] ) ? sanitize_key( wp_unslash( $_GET['shipment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$document_id = isset( $_GET['document'] ) ? sanitize_key( wp_unslash( $_GET['document'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.

		try {
			$file = $this->authorise( $account_id, $key, $document_id );
		} catch ( Shipment_Refused $e ) {
			wp_die( esc_html( $e->getMessage() ), '', [ 'response' => (int) ( $e->getCode() > 0 ? $e->getCode() : 409 ) ] );
		}

		$this->stream( $file );
	}

	/**
	 * Runs every check and reads the document.
	 *
	 * @since 2.1.28
	 *
	 * @param int    $account_id  Account.
	 * @param string $key         Shipment key.
	 * @param string $document_id Document id.
	 * @return Shipment_File
	 * @throws Shipment_Refused With the status to answer as its code (403 for a refused person, 409 or 502 otherwise).
	 */
	public function authorise( int $account_id, string $key, string $document_id ): Shipment_File {
		$allowed = is_user_logged_in() && false !== wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', $this->nonce_action( $account_id, $key, $document_id ) ) && current_user_can( 'edit_shop_orders' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the check.
		$account = $allowed ? $this->accounts->get( $account_id ) : null;

		if ( null === $account || $account->adapter_id() !== $this->adapter_id ) {
			throw new Shipment_Refused( __( 'У вас нет прав скачать этот файл.', 'woodev-plugin-framework' ), 403 );
		}

		return $this->service->document( $account, $key, $document_id );
	}

	/**
	 * Sends the file as a download, kept out of caches and treated as the type it is.
	 *
	 * @param Shipment_File $file Document.
	 * @return void
	 */
	private function stream( Shipment_File $file ): void {
		nocache_headers();
		header( 'Content-Type: ' . $file->content_type() );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file->filename() ) . '"' );
		header( 'Content-Length: ' . strlen( $file->body() ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $file->body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A binary document, not markup.
		exit;
	}
}
