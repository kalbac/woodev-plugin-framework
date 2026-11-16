<?php
/**
 * A product deleted for good lets go of its marketplace cards.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Without this, the link rows of a deleted product stay behind: the products screen shows the card as «Уже на сайте» with «Товар №N
 * (удалён)», and the importer never brings it in again. Permanent deletion is the one moment the rows can go, the same way «Снять
 * связь» ends them ({@see Link_Detacher::release_deleted()}).
 *
 * `before_delete_post` is the one hook every route reaches: the trash screen's «Удалить навсегда», the REST API, WP-CLI and
 * the deletion of a variable product's variations. Moving to the trash fires another hook, so a product restored from the trash keeps
 * its links. The marketplace is not called: its card stays where it is and can be imported or linked again.
 *
 * @since 2.1.8
 */
final class Deleted_Product_Releaser {
	private const POST_TYPES = [ 'product', 'product_variation' ];

	/**
	 * Detaches rows.
	 *
	 * @var Link_Detacher
	 */
	private $detacher;

	/**
	 * Error log.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Creates the releaser.
	 *
	 * @since 2.1.8
	 *
	 * @param Link_Detacher $detacher  Releases the rows.
	 * @param callable      $log_error `function ( string $line ): void`.
	 */
	public function __construct( Link_Detacher $detacher, callable $log_error ) {
		$this->detacher  = $detacher;
		$this->log_error = $log_error;
	}

	/**
	 * Hooks the deletion.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'on_delete' ], 10, 2 );
	}

	/**
	 * Releases the links of a product or variation that is being deleted. A failure is logged and never stops the deletion.
	 *
	 * @since 2.1.8
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    The post, when WordPress passes it.
	 * @return void
	 */
	public function on_delete( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );

		if ( ! in_array( $type, self::POST_TYPES, true ) ) {
			return;
		}

		try {
			$this->detacher->release_deleted( (int) $post_id );
		} catch ( \Throwable $e ) {
			call_user_func( $this->log_error, 'the links of deleted product ' . (int) $post_id . ' could not be released: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}
}
