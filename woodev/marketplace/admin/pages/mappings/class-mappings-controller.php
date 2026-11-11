<?php
/**
 * REST routes of the mappings and profiles view.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Admin\Records\Records_Error;
use Woodev\Framework\Marketplace\Price\Price_Source;

defined( 'ABSPATH' ) || exit;

/**
 * The server half of the mappings view, under `woodev/v1/admin/marketplace/{plugin id}/mappings` (`R`):
 *
 * | Route | Does |
 * |---|---|
 * | `GET R` | the first paint: sentences, lists, shop categories, form options |
 * | `GET R/categories?search=` | searches the marketplace categories |
 * | `GET R/fields?category=&mapping_id=` | the attribute table of a category, filled from that mapping's sources |
 * | `GET R/dictionary?category=&field=&search=` | searches the values of a dictionary attribute |
 * | `GET/POST/DELETE R/mappings[/{id}]` | mapping CRUD |
 * | `POST R/mappings/{id}/copy`, `POST R/mappings/{id}/check` | copy; one batch of the product check |
 * | `GET/POST/DELETE R/profiles[/{id}]` | profile CRUD |
 *
 * Every route runs the same order: the capability (and, from WordPress core, the cookie nonce) before anything is
 * read; the body checked against a declared key list (unknown keys → 400); the editor's own validation (→ 422 with
 * the PHP sentences); only then a write. The marketplace is asked only when the merchant acts (opening a category,
 * searching, checking), never on the first paint, and an adapter failure is a 502 with the adapter's sentence rather
 * than a blank screen. A mapping is account-agnostic, so no route takes an account.
 *
 * @since 2.1.0
 */
final class Mappings_Controller {
	/**
	 * Owner id as it appears in a route.
	 */
	public const OWNER_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

	/**
	 * Seconds a partial check result is kept between its batches.
	 */
	private const CHECK_KEEP = 600;

	/**
	 * Plugin id, the owner in the route.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Capability the user needs.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Mapping editor.
	 *
	 * @var Mapping_Editor
	 */
	private $mappings;

	/**
	 * Profile editor.
	 *
	 * @var Profile_Editor
	 */
	private $profiles;

	/**
	 * Product check.
	 *
	 * @var Mapping_Check
	 */
	private $check;

	/**
	 * Marketplace categories.
	 *
	 * @var Category_Finder
	 */
	private $categories;

	/**
	 * What the profile form offers.
	 *
	 * @var Profile_Choices
	 */
	private $choices;

	/**
	 * The marketplace's price source.
	 *
	 * @var Price_Source|null
	 */
	private $price_source;

	/**
	 * Built-in product fields a `field` source may name (key => label).
	 *
	 * @var array<string,string>
	 */
	private $product_fields;

	/**
	 * Creates the controller.
	 *
	 * @since 2.1.0
	 *
	 * @param string               $owner          Plugin id.
	 * @param string               $capability     Capability the user needs.
	 * @param Mapping_Editor       $mappings       Mapping editor.
	 * @param Profile_Editor       $profiles       Profile editor.
	 * @param Mapping_Check        $check          Product check.
	 * @param Category_Finder      $categories     Marketplace categories.
	 * @param Profile_Choices      $choices        What the profile form offers.
	 * @param Price_Source|null    $price_source   The marketplace's price source.
	 * @param array<string,string> $product_fields Product fields a `field` source may name.
	 */
	public function __construct(
		string $owner,
		string $capability,
		Mapping_Editor $mappings,
		Profile_Editor $profiles,
		Mapping_Check $check,
		Category_Finder $categories,
		Profile_Choices $choices,
		?Price_Source $price_source,
		array $product_fields
	) {
		$this->owner          = $owner;
		$this->capability     = $capability;
		$this->mappings       = $mappings;
		$this->profiles       = $profiles;
		$this->check          = $check;
		$this->categories     = $categories;
		$this->choices        = $choices;
		$this->price_source   = $price_source;
		$this->product_fields = $product_fields;
	}

	/**
	 * Registers the routes.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = '/admin/marketplace/' . $this->owner . '/mappings';
		$id   = '/(?P<id>[1-9][0-9]{0,18})';

		$routes = [
			$base                          => [ 'GET' => 'get_state' ],
			$base . '/categories'          => [ 'GET' => 'get_categories' ],
			$base . '/fields'              => [ 'GET' => 'get_fields' ],
			$base . '/dictionary'          => [ 'GET' => 'get_dictionary' ],
			$base . '/mappings'            => [ 'POST' => 'create_mapping' ],
			$base . '/mappings' . $id      => [
				'GET'    => 'get_mapping',
				'POST'   => 'update_mapping',
				'DELETE' => 'delete_mapping',
			],
			$base . '/mappings' . $id . '/copy'  => [ 'POST' => 'copy_mapping' ],
			$base . '/mappings' . $id . '/check' => [ 'POST' => 'check_mapping' ],
			$base . '/profiles'            => [ 'POST' => 'create_profile' ],
			$base . '/profiles' . $id      => [
				'GET'    => 'get_profile',
				'POST'   => 'update_profile',
				'DELETE' => 'delete_profile',
			],
		];

		foreach ( $routes as $pattern => $handlers ) {
			$endpoints = [];

			foreach ( $handlers as $method => $callback ) {
				$endpoints[] = [
					'methods'             => $method,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'check_permission' ],
				];
			}

			register_rest_route( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, $pattern, $endpoints );
		}
	}

	/**
	 * Gate for every route. The cookie nonce is checked by WordPress core before this runs; a valid nonce proves the
	 * request is the user's own, not that the user may manage mappings.
	 *
	 * @since 2.1.0
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		return current_user_can( $this->capability ) ? true : Records_Error::forbidden();
	}

	/**
	 * `GET R`: everything the first paint needs. It reads the adapter's category list (a cache read) for the labels of
	 * saved mappings and asks the marketplace for nothing else; a category list that cannot be read is a 502 with the
	 * adapter's sentence.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_state( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [] );

		if ( null !== $unknown ) {
			return $unknown;
		}

		return $this->remote(
			function (): \WP_REST_Response {
				return $this->respond(
					[
						'messages'          => View_Config::messages(),
						'mappings'          => $this->mappings->summaries(),
						'profiles'          => $this->profiles->summaries(),
						'shop_categories'   => $this->mappings->shop_categories(),
						'product_fields'    => View_Config::options( $this->product_fields ),
						'profile_form'      => View_Config::profile_form( $this->choices, $this->price_source ),
						'categories_loaded' => $this->categories->is_loaded(),
					]
				);
			}
		);
	}

	/**
	 * `GET R/categories`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_categories( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [ 'search' ] );

		if ( null !== $unknown ) {
			return $unknown;
		}

		return $this->remote(
			function () use ( $request ): \WP_REST_Response {
				$results = $this->categories->search( $this->query_text( $request, 'search' ) );

				return $this->respond( [ 'results' => $results ] );
			}
		);
	}

	/**
	 * `GET R/fields`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_fields( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [ 'category', 'mapping_id' ] );

		if ( null !== $unknown ) {
			return $unknown;
		}

		return $this->remote(
			function () use ( $request ) {
				$entry = $this->categories->find( $this->query_text( $request, 'category' ) );

				if ( null === $entry ) {
					return $this->unknown_category();
				}

				$sources = $this->sources_for( $this->query_int( $request, 'mapping_id' ), $entry );

				$rows    = $this->mappings->attribute_rows( $entry['identity'], $sources );

				return $this->respond( [ 'attributes' => $rows ] );
			}
		);
	}

	/**
	 * `GET R/dictionary`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_dictionary( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [ 'category', 'field', 'search' ] );

		if ( null !== $unknown ) {
			return $unknown;
		}

		return $this->remote(
			function () use ( $request ) {
				$entry = $this->categories->find( $this->query_text( $request, 'category' ) );

				if ( null === $entry ) {
					return $this->unknown_category();
				}

				$field  = $this->query_text( $request, 'field' );
				$search = $this->query_text( $request, 'search' );
				$values = $this->mappings->dictionary_values( $entry['identity'], $field, $search );

				return null === $values
					? $this->error( 'woodev_mappings_no_dictionary', Mapping_Messages::no_dictionary(), 400 )
					: $this->respond( [ 'results' => $values ] );
			}
		);
	}

	/**
	 * `GET R/mappings/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_mapping( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [] );

		if ( null !== $unknown ) {
			return $unknown;
		}

		return $this->remote(
			function () use ( $request ) {
				$detail = $this->mappings->detail( $this->route_id( $request ) );

				if ( null === $detail ) {
					return $this->error( 'woodev_mappings_not_found', Mapping_Messages::mapping_not_found(), 404 );
				}

				return $this->respond( $detail );
			}
		);
	}

	/**
	 * `POST R/mappings`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_mapping( \WP_REST_Request $request ) {
		return $this->save_mapping( $request, null );
	}

	/**
	 * `POST R/mappings/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_mapping( \WP_REST_Request $request ) {
		return $this->save_mapping( $request, $this->route_id( $request ) );
	}

	/**
	 * `POST R/mappings/{id}/copy`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function copy_mapping( \WP_REST_Request $request ) {
		return $this->answer( $this->mappings->copy( $this->route_id( $request ) ) );
	}

	/**
	 * `DELETE R/mappings/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_mapping( \WP_REST_Request $request ) {
		return $this->answer( $this->mappings->delete( $this->route_id( $request ) ) );
	}

	/**
	 * `POST R/mappings/{id}/check`: one batch of the product check.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_mapping( \WP_REST_Request $request ) {
		$body = $this->body( $request, [ 'offset', 'run' ] );

		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		return $this->remote(
			function () use ( $request, $body ) {
				return $this->run_check( $this->route_id( $request ), $body );
			}
		);
	}

	/**
	 * `GET R/profiles/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_profile( \WP_REST_Request $request ) {
		$unknown = $this->reject_unknown_query( $request, [] );
		$detail  = null === $unknown ? $this->profiles->detail( $this->route_id( $request ) ) : null;

		if ( null !== $unknown ) {
			return $unknown;
		}

		if ( null === $detail ) {
			return $this->error( 'woodev_mappings_not_found', Mapping_Messages::profile_not_found(), 404 );
		}

		return $this->respond( $detail );
	}

	/**
	 * `POST R/profiles`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_profile( \WP_REST_Request $request ) {
		return $this->save_profile( $request, null );
	}

	/**
	 * `POST R/profiles/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_profile( \WP_REST_Request $request ) {
		return $this->save_profile( $request, $this->route_id( $request ) );
	}

	/**
	 * `DELETE R/profiles/{id}`.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_profile( \WP_REST_Request $request ) {
		return $this->answer( $this->profiles->delete( $this->route_id( $request ) ) );
	}

	/**
	 * Saves a mapping body.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param int|null         $mapping_id Mapping id, null for a new one.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_mapping( \WP_REST_Request $request, ?int $mapping_id ) {
		$body = $this->body( $request, Mapping_Input::BODY_KEYS );

		return $body instanceof \WP_Error ? $body : $this->answer( $this->mappings->save( $mapping_id, $body ) );
	}

	/**
	 * Saves a profile body.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param int|null         $profile_id Profile id, null for a new one.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_profile( \WP_REST_Request $request, ?int $profile_id ) {
		$body = $this->body( $request, Profile_Input::body_keys( $this->choices ) );

		return $body instanceof \WP_Error ? $body : $this->answer( $this->profiles->save( $profile_id, $body ) );
	}

	/**
	 * Runs one batch of the check and keeps the partial result between batches.
	 *
	 * @param int                 $mapping_id Mapping id.
	 * @param array<string,mixed> $body       Checked body (`offset`, `run`).
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable When the adapter cannot give the fields of the category.
	 */
	private function run_check( int $mapping_id, array $body ) {
		$offset = isset( $body['offset'] ) && is_scalar( $body['offset'] ) ? max( 0, (int) $body['offset'] ) : 0;
		$run    = isset( $body['run'] ) && is_scalar( $body['run'] ) ? sanitize_key( (string) $body['run'] ) : '';
		$run    = substr( $run, 0, 40 );
		// Each click has its own run, so two overlapping checks never share a partial result.
		$key    = 'woodev_' . md5( $this->owner . '|' . get_current_user_id() . '|' . $mapping_id . '|' . $run );
		$carry  = $offset > 0 ? get_transient( $key ) : null;
		$resumed = is_array( $carry );
		$report  = $this->check->run( $mapping_id, $resumed ? $offset : 0, $resumed ? $carry : null );

		if ( null === $report ) {
			return $this->error( 'woodev_mappings_not_found', Mapping_Messages::mapping_not_found(), 404 );
		}

		if ( $report['next_offset'] > 0 ) {
			set_transient( $key, $report, self::CHECK_KEEP );
		} else {
			delete_transient( $key );
		}

		return $this->respond( self::check_view( $report ) );
	}

	/**
	 * Turns a check report into the sentences the view prints.
	 *
	 * @param array<string,mixed> $report Check report: `checked`, `ok`, `groups`, `total`, `next_offset`.
	 * @return array<string,mixed>
	 */
	private static function check_view( array $report ): array {
		$groups = [];
		foreach ( $report['groups'] as $group ) {
			$groups[] = [
				'text'  => Mapping_Messages::check_group( $group['count'], $group['message'] ),
				'count' => $group['count'],
			];
		}

		$done = 0 === $report['next_offset'];
		$text = $done
			? Mapping_Messages::check_summary( $report['checked'], $report['ok'] )
			: Mapping_Messages::check_progress( $report['checked'], $report['total'] );

		return [
			'done'        => $done,
			'next_offset' => $report['next_offset'],
			'groups'      => $groups,
			'text'        => $text,
		];
	}

	/**
	 * The stored sources of a mapping, but only when that mapping holds the category being asked about.
	 *
	 * @param int|null                                                    $mapping_id Mapping id from the query.
	 * @param array{key:string,label:string,identity:array<string,mixed>} $entry      The category.
	 * @return array<string,mixed>
	 */
	private function sources_for( ?int $mapping_id, array $entry ): array {
		$detail = null === $mapping_id ? null : $this->mappings->stored( $mapping_id );

		if ( null === $detail || Category_Finder::key_of( $detail['category'] ) !== $entry['key'] ) {
			return [];
		}

		return $detail['attributes'];
	}

	/**
	 * Maps a write result to a response.
	 *
	 * @param array{status:string,id:int,errors:string[],notices?:string[]} $result Result of an editor write.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function answer( array $result ) {
		if ( 'saved' === $result['status'] ) {
			return $this->respond(
				[
					'id'      => $result['id'],
					'notices' => $result['notices'] ?? [],
				]
			);
		}

		$statuses = [
			'invalid'     => 422,
			'missing'     => 404,
			'in_use'      => 409,
			'unavailable' => 502,
		];

		$message = $result['errors'][0] ?? Mapping_Messages::save_failed();
		$status  = $statuses[ $result['status'] ] ?? 500;

		return $this->error( 'woodev_mappings_' . $result['status'], $message, $status, $result['errors'] );
	}

	/**
	 * Runs a read that may reach the adapter and turns its failure into a 502 with the adapter's sentence.
	 *
	 * @param callable $read Does the read and returns a response.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function remote( callable $read ) {
		try {
			return $read();
		} catch ( \Throwable $e ) {
			$message = Mapping_Messages::marketplace_failure( $e->getMessage() );

			return $this->error( 'woodev_mappings_unavailable', $message, 502 );
		}
	}

	/**
	 * Reads the JSON body and refuses keys the route does not declare.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]         $allowed Keys the body may hold.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function body( \WP_REST_Request $request, array $allowed ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->error( 'woodev_mappings_malformed', Mapping_Messages::malformed(), 400 );
		}

		if ( [] !== array_diff( array_keys( $body ), $allowed ) ) {
			return $this->error( 'woodev_mappings_unknown_field', Mapping_Messages::malformed(), 400 );
		}

		return $body;
	}

	/**
	 * Refuses query parameters the route does not declare.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]         $allowed Parameters the route accepts.
	 * @return \WP_Error|null Null when every parameter is declared.
	 */
	private function reject_unknown_query( \WP_REST_Request $request, array $allowed ): ?\WP_Error {
		$unknown = array_diff( array_keys( $request->get_query_params() ), $allowed );

		if ( [] === $unknown ) {
			return null;
		}

		return $this->error( 'woodev_mappings_unknown_field', Mapping_Messages::malformed(), 400 );
	}

	/**
	 * Reads a text query parameter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Parameter.
	 * @return string
	 */
	private function query_text( \WP_REST_Request $request, string $name ): string {
		$params = $request->get_query_params();

		if ( ! isset( $params[ $name ] ) || ! is_scalar( $params[ $name ] ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $params[ $name ] ) );
	}

	/**
	 * Reads a positive integer query parameter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Parameter.
	 * @return int|null
	 */
	private function query_int( \WP_REST_Request $request, string $name ): ?int {
		$value = (int) $this->query_text( $request, $name );

		return $value > 0 ? $value : null;
	}

	/**
	 * Reads the id from the path (the URL params only: `get_param()` lets a query-string value of the same name win).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	private function route_id( \WP_REST_Request $request ): int {
		$params = $request->get_url_params();

		return isset( $params['id'] ) && is_scalar( $params['id'] ) ? (int) $params['id'] : 0;
	}

	/**
	 * The answer to a category key the schema does not offer.
	 *
	 * @return \WP_Error
	 */
	private function unknown_category(): \WP_Error {
		return $this->error( 'woodev_mappings_unknown_category', Mapping_Messages::unknown_category(), 400 );
	}

	/**
	 * Builds an error with the status and, for a validation failure, every line.
	 *
	 * @param string   $code    Error code.
	 * @param string   $message The sentence the merchant reads.
	 * @param int      $status  HTTP status.
	 * @param string[] $errors  Every error line, when there are several.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status, array $errors = [] ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status' => $status,
				'errors' => $errors,
			]
		);
	}

	/**
	 * Wraps a body in a private, uncacheable response.
	 *
	 * @param array<string,mixed> $data Body.
	 * @return \WP_REST_Response
	 */
	private function respond( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}
}
