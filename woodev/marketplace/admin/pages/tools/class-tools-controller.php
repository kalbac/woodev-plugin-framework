<?php
/**
 * REST routes of the «Инструменты» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Admin\Records\Records_Error;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The server half of the tools page, under `woodev/v1/admin/marketplace/{plugin id}/tools`:
 *
 * | Route | Does |
 * |---|---|
 * | `GET R?account_id=` | the tools, the accounts to choose from, and the account line (busy or idle) |
 * | `POST R/{tool}/preview` `{account_id}` | the preview sentence and the disabled state; changes nothing |
 * | `POST R/{tool}/run` `{account_id, confirmed: true}` | the confirmed run; 409 while the tool is disabled |
 *
 * Every route runs the same order: the capability before anything is read; the body checked against a declared key list
 * (unknown keys → 400); the tool and the account resolved on this plugin's adapter (unknown → 404); only then the work.
 * Results are `{status, success, message, activity}` so the page shows the sentence the tool gave, never a code.
 *
 * @since 2.1.5
 */
final class Tools_Controller {
	/**
	 * Pattern of a tool id in a route.
	 */
	private const TOOL_PATTERN = '[a-z0-9][a-z0-9_-]{0,63}';

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
	 * Adapter id: an account of another marketplace is not an account of this page.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * @var Tool_Registry
	 */
	private $registry;

	/**
	 * @var Tool_Catalogue
	 */
	private $catalogue;

	/**
	 * @var Tool_Activity
	 */
	private $activity;

	/**
	 * @var Tool_Runner
	 */
	private $runner;

	/**
	 * @since 2.1.5
	 *
	 * @param string        $owner      Plugin id.
	 * @param string        $capability Capability the page needs.
	 * @param string        $adapter_id Adapter id.
	 * @param Account_Store $accounts   Accounts of the plugin.
	 * @param Tool_Registry $registry   Tools of the page.
	 * @param Tool_Activity $activity   Account activity reader.
	 * @param Tool_Runner   $runner     Runs a confirmed tool.
	 */
	public function __construct( string $owner, string $capability, string $adapter_id, Account_Store $accounts, Tool_Registry $registry, Tool_Activity $activity, Tool_Runner $runner ) {
		$this->owner      = $owner;
		$this->capability = $capability;
		$this->adapter_id = $adapter_id;
		$this->accounts   = $accounts;
		$this->registry   = $registry;
		$this->catalogue  = new Tool_Catalogue( $registry, $activity );
		$this->activity   = $activity;
		$this->runner     = $runner;
	}

	/**
	 * Registers the routes.
	 *
	 * @since 2.1.5
	 *
	 * @internal
	 * @return void
	 */
	public function register_routes(): void {
		$base = '/admin/marketplace/' . $this->owner . '/tools';
		$tool = '/(?P<tool>' . self::TOOL_PATTERN . ')';

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			$base,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_state' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			$base . $tool . '/preview',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'preview' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);

		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			$base . $tool . '/run',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'run' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * Gate for every route. The cookie nonce is checked by WordPress core; a valid nonce proves the request is the user's own,
	 * not that the user may manage the tools.
	 *
	 * @since 2.1.5
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		return current_user_can( $this->capability ) ? true : Records_Error::forbidden();
	}

	/**
	 * `GET R`: the tools for the chosen account (or for none yet), the accounts and the account line.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_state( \WP_REST_Request $request ) {
		$account_id = $this->query_account_id( $request );

		if ( is_wp_error( $account_id ) ) {
			return $account_id;
		}

		return new \WP_REST_Response(
			[
				'messages' => self::messages(),
				'accounts' => $this->account_choices(),
				'tools'    => $this->catalogue->list( $account_id ?? 0 ),
				'activity' => $this->activity->account( $account_id ?? 0 ),
			]
		);
	}

	/**
	 * The sentences and labels of the page. PHP owns the copy; the view only places it.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return [
			'accountLabel'  => __( 'Аккаунт', 'woodev-plugin-framework' ),
			'chooseAccount' => __( 'Выберите аккаунт, чтобы запускать инструменты.', 'woodev-plugin-framework' ),
			'noTools'       => __( 'Для этого маркетплейса пока нет инструментов.', 'woodev-plugin-framework' ),
			'previewButton' => __( 'Проверить', 'woodev-plugin-framework' ),
			'cancelButton'  => __( 'Отмена', 'woodev-plugin-framework' ),
			'running'       => __( 'Выполняется…', 'woodev-plugin-framework' ),
			'loadFailed'    => __( 'Не удалось загрузить инструменты. Обновите страницу.', 'woodev-plugin-framework' ),
			'runFailed'     => __( 'Инструмент не выполнен.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * `POST R/{tool}/preview`: the preview sentence for an account. Changes nothing.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$resolved = $this->resolve( $request, [ 'account_id' ] );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		[ $tool, $account_id ] = $resolved;
		$state                 = $this->state_of( $tool, $account_id );

		return new \WP_REST_Response(
			[
				'tool'            => $tool->id(),
				'summary'         => $tool->summary( $account_id ),
				'disabled'        => $state['disabled'],
				'disabled_reason' => $state['reason'],
				'confirm_label'   => $tool->describe()['button'],
				'can_run'         => ! $state['disabled'],
			]
		);
	}

	/**
	 * `POST R/{tool}/run`: the confirmed run. A disabled tool is refused with 409 and its sentence; the rest is the runner's.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$resolved = $this->resolve( $request, [ 'account_id', 'confirmed' ] );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		[ $tool, $account_id ] = $resolved;

		if ( true !== $request->get_param( 'confirmed' ) ) {
			return $this->error( 'woodev_tools_not_confirmed', __( 'Запуск не подтверждён.', 'woodev-plugin-framework' ), 400 );
		}

		$state = $this->state_of( $tool, $account_id );

		if ( $state['disabled'] ) {
			return $this->error( 'woodev_tools_disabled', $state['reason'], 409 );
		}

		$result = $this->runner->run( $tool->id(), $account_id );

		return new \WP_REST_Response(
			[
				'status'   => $result->is_success() ? 'done' : 'failed',
				'success'  => $result->is_success(),
				'message'  => $result->get_message(),
				'activity' => $this->activity->account( $account_id ),
			]
		);
	}

	/**
	 * The disabled state of a tool for an account, read now.
	 *
	 * @since 2.1.5
	 *
	 * @param Tool $tool       Tool.
	 * @param int  $account_id Account.
	 * @return array{disabled:bool,reason:string}
	 */
	private function state_of( Tool $tool, int $account_id ): array {
		return Tool_Catalogue::state( $tool->takes_claim(), $this->activity->claim_held( $account_id ) );
	}

	/**
	 * Checks the body keys, then resolves the tool of the route and the account of the body.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]         $allowed Keys the body may carry.
	 * @return array{0:Tool,1:int}|\WP_Error
	 */
	private function resolve( \WP_REST_Request $request, array $allowed ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : [];
		$unknown = array_diff( array_keys( $body ), $allowed );

		if ( [] !== $unknown ) {
			return $this->error( 'woodev_tools_unknown_field', __( 'В запросе есть поля, которых не бывает.', 'woodev-plugin-framework' ), 400 );
		}

		$tool = $this->registry->get( (string) $request->get_param( 'tool' ) );

		if ( null === $tool ) {
			return $this->error( 'woodev_tools_unknown_tool', __( 'Такого инструмента нет.', 'woodev-plugin-framework' ), 404 );
		}

		$account_id = self::positive_int( $body['account_id'] ?? null );
		$account    = null === $account_id ? null : $this->account( $account_id );

		if ( null === $account ) {
			return $this->error( 'woodev_tools_unknown_account', __( 'Аккаунт не найден. Обновите страницу.', 'woodev-plugin-framework' ), 404 );
		}

		if ( ! $account->is_enabled() ) {
			return $this->error( 'woodev_tools_account_disabled', __( 'Аккаунт выключен. Включите его в настройках плагина.', 'woodev-plugin-framework' ), 409 );
		}

		return [ $tool, $account_id ];
	}

	/**
	 * The account a request names, when it is one of this plugin's accounts.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return Account|null
	 */
	private function account( int $account_id ): ?Account {
		$account = $this->accounts->get( $account_id );

		return null !== $account && $account->adapter_id() === $this->adapter_id ? $account : null;
	}

	/**
	 * The accounts the merchant can choose from: this plugin's, switched on or off, by name.
	 *
	 * @since 2.1.5
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function account_choices(): array {
		$choices = [];

		foreach ( $this->accounts->all( $this->adapter_id, false ) as $account ) {
			$choices[] = [
				'id'   => $account->id(),
				'name' => $account->name(),
			];
		}

		return $choices;
	}

	/**
	 * The account a `GET` names in its query, or null when none is chosen yet.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int|null|\WP_Error
	 */
	private function query_account_id( \WP_REST_Request $request ) {
		$raw = $request->get_param( 'account_id' );

		if ( null === $raw || '' === $raw ) {
			return null;
		}

		$account_id = self::positive_int( $raw );

		return null === $account_id ? $this->error( 'woodev_tools_bad_account', __( 'Номер аккаунта указан неверно.', 'woodev-plugin-framework' ), 400 ) : $account_id;
	}

	/**
	 * A positive whole number from a request value, or null. A text of digits is accepted; anything else is not an account.
	 *
	 * @since 2.1.5
	 *
	 * @param mixed $value Request value.
	 * @return int|null
	 */
	public static function positive_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $value ) ? (int) $value : null;
	}

	/**
	 * A REST error with its status.
	 *
	 * @since 2.1.5
	 *
	 * @param string $code    Machine code.
	 * @param string $message Sentence for the merchant.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
