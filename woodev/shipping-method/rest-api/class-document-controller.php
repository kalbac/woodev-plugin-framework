<?php
/** REST downloads for carrier documents. */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Document_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves on-demand carrier documents without persisting PDF contents.
 *
 * @since 2.0.2
 */
class Document_Controller extends \WP_REST_Controller {
	private Orders_Registry $registry;

	/** @since 2.0.2 @param Orders_Registry $registry carrier registry */
	public function __construct( Orders_Registry $registry ) {
		$this->registry = $registry;
	}

	/** @since 2.0.2 @return void */
	public function register_routes(): void {
		add_filter( 'rest_pre_serve_request', [ $this, 'serve_binary_response' ], 10, 4 );
		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			'/shipping/orders/(?P<id>\d+)/documents/(?P<type>[a-z0-9_-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'download' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'id' => [ 'type' => 'integer' ],
					'type' => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Serves PDF response bodies verbatim; the default REST encoder would JSON-quote a string.
	 *
	 * @since 2.0.2
	 * @param bool              $served whether an earlier callback served the response.
	 * @param \WP_HTTP_Response $result response to serve.
	 * @param \WP_REST_Request  $request REST request.
	 * @param \WP_REST_Server   $server REST server.
	 * @return bool
	 */
	public function serve_binary_response( $served, $result, $request, $server ): bool {
		$route_pattern = '~/' . preg_quote( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, '~' ) . '/shipping/orders/\d+/documents/[a-z0-9_-]+$~';
		if ( $served || ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || 200 !== $result->get_status() || 'application/pdf' !== ( $result->get_headers()['Content-Type'] ?? '' ) || 1 !== preg_match( $route_pattern, $request->get_route() ) ) {
			return (bool) $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- carrier PDF bytes are not text.
		return true;
	}

	/** @since 2.0.2 @param \WP_REST_Request $request REST request @return bool|\WP_Error */
	public function permissions_check( \WP_REST_Request $request ) {
		$order_id = absint( $request['id'] );
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'woodev_document_forbidden', __( 'У вас нет прав для скачивания документа.', 'woodev-plugin-framework' ), [ 'status' => 403 ] );
		}
		if ( ! wc_get_order( $order_id ) ) {
			return new \WP_Error( 'woodev_document_order_missing', __( 'Заказ не найден.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}
		return true;
	}

	/** @since 2.0.2 @param \WP_REST_Request $request REST request @return \WP_REST_Response|\WP_Error */
	public function download( \WP_REST_Request $request ) {
		$order    = wc_get_order( absint( $request['id'] ) );
		$provider = $order ? $this->registry->resolve_provider_for_order( $order ) : null;
		$source   = null !== $provider ? $this->registry->get_document_source( $provider->get_id() ) : null;
		$type     = sanitize_key( (string) $request['type'] );

		if ( ! $order || null === $provider || ! $provider->supports_label_printing() || null === $source || null === $provider->get_carrier_order_id_meta_key() || '' === (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_carrier_order_id_meta_key() ) || ! in_array( $type, $source->get_document_types( $order ), true ) ) {
			return new \WP_Error( 'woodev_document_unavailable', __( 'Документ недоступен.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}

		try {
			$result = $source->get_document( $order, $type );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}
		if ( ! $result instanceof Document_Result ) {
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}
		if ( Document_Result::PENDING === $result->get_state() ) {
			$response = new \WP_REST_Response(
				[
					'status' => 'pending',
					'message' => __( 'Документ ещё готовится. Повторите запрос позже.', 'woodev-plugin-framework' ),
				],
				202
			);
			$response->header( 'Retry-After', (string) max( 1, $result->get_retry_after() ) );
			return $response;
		}
		if ( Document_Result::FAILED === $result->get_state() ) {
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}
		if ( Document_Result::READY_URL === $result->get_state() ) {
			$url = esc_url_raw( $result->get_value(), [ 'https', 'http' ] );
			if ( '' === $url ) {
				return new \WP_Error( 'woodev_document_failed', __( 'Ссылка на документ некорректна.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
			}
			\Woodev_Order_Compatibility::update_order_meta( $order, '_woodev_shipping_document_downloaded_' . $provider->get_id() . '_' . $type, time() );
			$response = new \WP_REST_Response( null, 302 );
			$response->header( 'Location', $url );
			return $response;
		}
		if ( Document_Result::READY_BINARY !== $result->get_state() || '' === $result->get_value() ) {
			return new \WP_Error( 'woodev_document_failed', __( 'Документ пуст или имеет неверный формат.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}

		\Woodev_Order_Compatibility::update_order_meta( $order, '_woodev_shipping_document_downloaded_' . $provider->get_id() . '_' . $type, time() );
		$response = new \WP_REST_Response( $result->get_value(), 200 );
		$response->header( 'Content-Type', 'application/pdf' );
		$response->header( 'Content-Disposition', 'attachment; filename="order-' . absint( $order->get_id() ) . '-' . sanitize_file_name( $type ) . '.pdf"' );
		return $response;
	}
}
