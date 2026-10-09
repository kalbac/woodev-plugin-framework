<?php
/** REST downloads for carrier documents. */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Bulk_Document_Source;
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
	/**
	 * Ceiling on the order ids ONE bulk request may carry. The real limit is the carrier's
	 * ({@see Bulk_Document_Source::get_bulk_document_limit()}); this only keeps an absurd query string out.
	 */
	private const BULK_MAX_IDS = 500;

	/**
	 * Why an order is missing from a bulk document — the codes the client turns into sentences, so no Russian
	 * travels in a response header.
	 *
	 * `not_found`: no such order. `not_shipping`: not a printing carrier's order. `not_exported`: not handed to the
	 * carrier yet. `unsupported`: the carrier does not offer this document for it. `carrier_skipped`: the carrier
	 * answered without it.
	 */
	public const SKIP_CODES = [ 'not_found', 'not_shipping', 'not_exported', 'unsupported', 'carrier_skipped' ];

	/** Response header naming the orders a bulk document leaves out: `id:code,id:code`. */
	public const SKIPPED_HEADER = 'X-Woodev-Skipped';

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
					'id'     => [ 'type' => 'integer' ],
					'type'   => [ 'type' => 'string' ],
					// `json`: a carrier link is answered as `{status:'url', url}` instead of a 302, for the admin UI,
					// which cannot read a cross-origin redirect.
					'format' => [
						'type' => 'string',
						'enum' => [ 'file', 'json' ],
					],
				],
			]
		);

		// One document for SEVERAL orders (#1192): `GET …/shipping/orders/documents/<type>?ids=1,2,3`. Same
		// 202-pending / binary / link contract as the single route, so the client polls both the same way.
		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			'/shipping/orders/documents/(?P<type>[a-z0-9_-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'download_bulk' ],
				'permission_callback' => [ $this, 'bulk_permissions_check' ],
				'args'                => [
					'type'   => [ 'type' => 'string' ],
					// Comma separated (`ids=1,2,3`) or a real array; WordPress turns the first into the second.
					'ids'    => [
						'type'              => 'array',
						'required'          => true,
						'items'             => [ 'type' => 'integer' ],
						'validate_callback' => [ __CLASS__, 'validate_bulk_ids' ],
					],
					'format' => [
						'type' => 'string',
						'enum' => [ 'file', 'json' ],
					],
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
		$route_pattern = '~/' . preg_quote( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, '~' ) . '/shipping/orders/(?:\d+/)?documents/[a-z0-9_-]+$~';
		if ( $served || ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || 200 !== $result->get_status() || 'application/pdf' !== ( $result->get_headers()['Content-Type'] ?? '' ) || 1 !== preg_match( $route_pattern, $request->get_route() ) ) {
			return (bool) $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- carrier PDF bytes are not text.
		return true;
	}

	public function permissions_check( \WP_REST_Request $request ) {
		$denied = $this->check_access( $request );
		if ( null !== $denied ) {
			return $denied;
		}
		if ( ! wc_get_order( absint( $request->get_param( 'id' ) ) ) ) {
			return new \WP_Error( 'woodev_document_order_missing', __( 'Заказ не найден.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}
		return true;
	}

	/**
	 * Permission gate of the bulk route: the same capability and nonce as one document. The orders themselves are
	 * checked one by one in the callback, where a missing one is reported rather than turning the whole request into a 404.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function bulk_permissions_check( \WP_REST_Request $request ) {
		return $this->check_access( $request ) ?? true;
	}

	/**
	 * Capability and REST nonce.
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_Error|null the refusal, or null when the caller may download documents.
	 */
	private function check_access( \WP_REST_Request $request ): ?\WP_Error {
		// `get_header()` returns NULL for an absent header, not '' — cast, or the `_wpnonce` fallback never runs.
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'woodev_document_forbidden', __( 'У вас нет прав для скачивания документа.', 'woodev-plugin-framework' ), [ 'status' => 403 ] );
		}
		return null;
	}

	public function download( \WP_REST_Request $request ) {
		$order    = wc_get_order( absint( $request->get_param( 'id' ) ) );
		$provider = $order ? $this->registry->resolve_provider_for_order( $order ) : null;
		$source   = null !== $provider ? $this->registry->get_document_source( $provider->get_id() ) : null;
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );

		if ( ! $order || null === $provider || ! $provider->supports_label_printing() || null === $source || null === $provider->get_carrier_order_id_meta_key() || '' === (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_carrier_order_id_meta_key() ) || ! in_array( $type, $source->get_document_types( $order ), true ) ) {
			return new \WP_Error( 'woodev_document_unavailable', __( 'Документ недоступен.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}

		try {
			$result = $source->get_document( $order, $type );
		} catch ( \Throwable $error ) {
			$this->log( $provider->get_id(), $type, 'threw ' . get_class( $error ) . ': ' . $error->getMessage() );
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}

		return $this->respond(
			$result,
			$provider->get_id(),
			$type,
			[ $order ],
			'order-' . absint( $order->get_id() ) . '-' . sanitize_file_name( $type ) . '.pdf',
			'json' === (string) $request->get_param( 'format' ),
			[]
		);
	}

	/**
	 * `GET /shipping/orders/documents/<type>?ids=…` — ONE document for several orders (#1192).
	 *
	 * Decisions, in the order the merchant meets them:
	 * - an order the framework cannot print (unknown, not a printing carrier's, not handed to the carrier yet, type
	 *   not offered) is LEFT OUT and named in the answer — it never fails the request;
	 * - orders of SEVERAL carriers are refused with a clear message: each carrier prints its own document, so there
	 *   is no single file to hand back, and several automatic downloads from one click are blocked by browsers;
	 * - more orders than the carrier takes in one document are refused too, rather than split into several files;
	 * - a carrier without {@see Bulk_Document_Source} is not offered the capability at all (404).
	 *
	 * @since 2.0.2
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download_bulk( \WP_REST_Request $request ) {
		$type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$skipped = [];
		$orders  = [];

		foreach ( self::parse_ids( $request->get_param( 'ids' ) ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				$skipped[ $id ] = 'not_found';
				continue;
			}

			$provider = $this->registry->resolve_provider_for_order( $order );
			$source   = null !== $provider ? $this->registry->get_document_source( $provider->get_id() ) : null;
			$meta_key = null !== $provider ? $provider->get_carrier_order_id_meta_key() : null;

			if ( null === $provider || ! $provider->supports_label_printing() || null === $source || null === $meta_key ) {
				$skipped[ $id ] = 'not_shipping';
			} elseif ( '' === (string) \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key ) ) {
				$skipped[ $id ] = 'not_exported';
			} elseif ( ! in_array( $type, $source->get_document_types( $order ), true ) ) {
				$skipped[ $id ] = 'unsupported';
			} else {
				$orders[ $provider->get_id() ][ $id ] = $order;
			}
		}

		if ( [] === $orders ) {
			return new \WP_Error( 'woodev_document_nothing_to_print', __( 'Для выбранных заказов нет документов, которые можно напечатать. Документ доступен только после выгрузки заказа перевозчику.', 'woodev-plugin-framework' ), [ 'status' => 422 ] );
		}
		if ( count( $orders ) > 1 ) {
			return new \WP_Error( 'woodev_document_mixed_carriers', __( 'Выбраны заказы разных перевозчиков, а у каждого перевозчика свои документы. Выберите заказы одного перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 422 ] );
		}

		$provider_id = (string) array_key_first( $orders );
		$selected    = $orders[ $provider_id ];
		$source      = $this->registry->get_document_source( $provider_id );

		if ( ! $source instanceof Bulk_Document_Source || ! in_array( $type, $source->get_bulk_document_types(), true ) ) {
			return new \WP_Error( 'woodev_document_unavailable', __( 'Массовая печать этого документа для выбранного перевозчика недоступна.', 'woodev-plugin-framework' ), [ 'status' => 404 ] );
		}

		$limit = max( 1, $source->get_bulk_document_limit() );
		if ( count( $selected ) > $limit ) {
			return new \WP_Error(
				'woodev_document_bulk_too_many',
				sprintf(
					/* translators: %d: the most orders the carrier prints in one document. */
					__( 'За один раз можно напечатать не более %d заказов. Выберите меньше заказов.', 'woodev-plugin-framework' ),
					$limit
				),
				[ 'status' => 422 ]
			);
		}

		try {
			$result = $source->get_bulk_document( array_values( $selected ), $type );
		} catch ( \Throwable $error ) {
			$this->log( $provider_id, $type, 'bulk threw ' . get_class( $error ) . ': ' . $error->getMessage() );
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}

		if ( $result instanceof Document_Result ) {
			// Only ids this request really sent count: a carrier cannot "skip" an order it was never given.
			foreach ( $result->get_skipped_order_ids() as $id ) {
				if ( isset( $selected[ $id ] ) ) {
					$skipped[ $id ] = 'carrier_skipped';
					unset( $selected[ $id ] );
				}
			}
		}

		return $this->respond(
			$result,
			$provider_id,
			$type,
			array_values( $selected ),
			'orders-' . sanitize_file_name( $type ) . '-' . gmdate( 'Ymd' ) . '.pdf',
			'json' === (string) $request->get_param( 'format' ),
			$skipped
		);
	}

	/**
	 * REST `validate_callback` for `ids` on the bulk route: refuses an absurdly long list instead of truncating it.
	 *
	 * @since 2.0.2
	 * @param mixed $value the request value.
	 * @return bool|\WP_Error
	 */
	public static function validate_bulk_ids( $value ) {
		if ( [] === self::parse_ids( $value ) ) {
			return new \WP_Error( 'woodev_document_no_orders', __( 'Не выбрано ни одного заказа.', 'woodev-plugin-framework' ), [ 'status' => 400 ] );
		}
		if ( count( self::parse_ids( $value ) ) > self::BULK_MAX_IDS ) {
			return new \WP_Error(
				'woodev_document_bulk_too_many',
				sprintf(
					/* translators: %d: the most orders one request may carry. */
					__( 'За один раз можно напечатать не более %d заказов. Выберите меньше заказов.', 'woodev-plugin-framework' ),
					self::BULK_MAX_IDS
				),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Positive, unique order ids from `1,2,3` or an array, in the order given.
	 *
	 * @since 2.0.2
	 * @param mixed $raw the `ids` parameter.
	 * @return int[]
	 */
	private static function parse_ids( $raw ): array {
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids  = [];
		foreach ( $list as $item ) {
			$id = absint( $item );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Turns a {@see Document_Result} into the HTTP answer both routes share: 202 pending, a 302 or JSON link, a PDF
	 * attachment, or a generic 502 (the carrier's own words go to the log, never to the merchant).
	 *
	 * @since 2.0.2
	 * @param mixed             $result      what the source returned.
	 * @param string            $provider_id carrier id.
	 * @param string            $type        document type.
	 * @param \WC_Order[]       $printed     the orders the document covers; marked as downloaded once it is served.
	 * @param string            $filename    attachment name.
	 * @param bool              $json        whether a carrier link is answered as JSON instead of a redirect.
	 * @param array<int,string> $skipped     order id => {@see self::SKIP_CODES} of the orders left out (bulk only).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( $result, string $provider_id, string $type, array $printed, string $filename, bool $json, array $skipped ) {
		if ( ! $result instanceof Document_Result ) {
			$this->log( $provider_id, $type, 'returned something other than a Document_Result' );
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}
		if ( Document_Result::PENDING === $result->get_state() ) {
			$response = new \WP_REST_Response(
				[
					'status'      => 'pending',
					'message'     => __( 'Документ ещё готовится. Повторите запрос позже.', 'woodev-plugin-framework' ),
					'retry_after' => max( 1, $result->get_retry_after() ),
				],
				202
			);
			$response->header( 'Retry-After', (string) max( 1, $result->get_retry_after() ) );
			return $response;
		}
		if ( Document_Result::FAILED === $result->get_state() ) {
			$this->log( $provider_id, $type, 'failed: ' . $result->get_value() );
			return new \WP_Error( 'woodev_document_failed', __( 'Не удалось получить документ у перевозчика.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}
		if ( Document_Result::READY_URL === $result->get_state() ) {
			// https only: a waybill link carries a shipment id and must not travel in clear text.
			$url = esc_url_raw( $result->get_value(), [ 'https' ] );
			if ( '' === $url ) {
				return new \WP_Error( 'woodev_document_failed', __( 'Ссылка на документ некорректна.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
			}
			$this->mark_downloaded( $printed, $provider_id, $type );
			if ( $json ) {
				$body = [
					'status' => 'url',
					'url'    => $url,
				];
				if ( [] !== $skipped ) {
					$body['skipped'] = self::skipped_entries( $skipped );
				}
				return new \WP_REST_Response( $body, 200 );
			}
			$response = new \WP_REST_Response( null, 302 );
			$response->header( 'Location', $url );
			return $response;
		}
		if ( Document_Result::READY_BINARY !== $result->get_state() || '' === $result->get_value() ) {
			return new \WP_Error( 'woodev_document_failed', __( 'Документ пуст или имеет неверный формат.', 'woodev-plugin-framework' ), [ 'status' => 502 ] );
		}

		$this->mark_downloaded( $printed, $provider_id, $type );
		$response = new \WP_REST_Response( $result->get_value(), 200 );
		$response->header( 'Content-Type', 'application/pdf' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		if ( [] !== $skipped ) {
			$response->header(
				self::SKIPPED_HEADER,
				implode(
					',',
					array_map(
						static function ( array $entry ): string {
							return $entry['id'] . ':' . $entry['code'];
						},
						self::skipped_entries( $skipped )
					)
				)
			);
		}
		return $response;
	}

	/**
	 * @since 2.0.2
	 * @param array<int,string> $skipped order id => code.
	 * @return array<int,array{id:int,code:string}>
	 */
	private static function skipped_entries( array $skipped ): array {
		$entries = [];
		foreach ( $skipped as $id => $code ) {
			$entries[] = [
				'id'   => (int) $id,
				'code' => in_array( $code, self::SKIP_CODES, true ) ? $code : 'carrier_skipped',
			];
		}
		return $entries;
	}

	/**
	 * Remembers that the document was handed out for each order.
	 *
	 * @since 2.0.2
	 * @param \WC_Order[] $orders      orders the document covers.
	 * @param string      $provider_id carrier id.
	 * @param string      $type        document type.
	 * @return void
	 */
	private function mark_downloaded( array $orders, string $provider_id, string $type ): void {
		foreach ( $orders as $order ) {
			\Woodev_Order_Compatibility::update_order_meta( $order, '_woodev_shipping_document_downloaded_' . $provider_id . '_' . $type, time() );
		}
	}

	/**
	 * Writes a document failure to the WooCommerce log; the merchant sees a generic message only.
	 *
	 * @since 2.0.2
	 * @param string $provider_id Carrier id.
	 * @param string $type        Document type.
	 * @param string $message     What happened.
	 * @return void
	 */
	private function log( string $provider_id, string $type, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( sprintf( 'Carrier document %s/%s: %s', $provider_id, $type, $message ), [ 'source' => 'woodev-shipping-documents' ] );
		}
	}
}
