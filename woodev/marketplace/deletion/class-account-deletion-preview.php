<?php
/**
 * What deleting an account would remove, and what the merchant must know before agreeing.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only: four counts (links, tasks, postings, documents), the sentence that WooCommerce orders stay, and the warnings that
 * apply to this account. Changes nothing and calls no marketplace.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Preview {
	/**
	 * Counters by what they count: `function ( int $account_id ): int`.
	 *
	 * @var array<string,callable>
	 */
	private $counters;

	/**
	 * Resolver of the account's order import switch.
	 *
	 * @var Effective_Settings
	 */
	private $settings;

	/**
	 * Whether the marketplace has push notifications.
	 *
	 * @var bool
	 */
	private $has_push;

	/**
	 * Creates the preview.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,callable> $counters Keys `links`, `tasks`, `postings`, `documents`, each `function ( int $account_id ): int`.
	 * @param Effective_Settings     $settings Resolver of `import_enabled`.
	 * @param bool                   $has_push Whether the marketplace has push notifications.
	 */
	public function __construct( array $counters, Effective_Settings $settings, bool $has_push ) {
		$this->counters = $counters;
		$this->settings = $settings;
		$this->has_push = $has_push;
	}

	/**
	 * Describes the deletion of an account.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account.
	 * @return array{counts:array<int,array{key:string,label:string,count:int}>,kept:string,warnings:string[]}
	 */
	public function describe( Account $account ): array {
		$numbers = $this->numbers( $account->id() );
		$counts  = [];

		foreach ( self::labels() as $key => $label ) {
			$counts[] = [
				'key'   => $key,
				'label' => $label,
				'count' => $numbers[ $key ],
			];
		}

		return [
			'counts'   => $counts,
			'kept'     => __( 'Заказы WooCommerce остаются в магазине: они удаляются отдельно.', 'woodev-plugin-framework' ),
			'warnings' => $this->warnings( $account, $numbers['postings'] ),
		];
	}

	/**
	 * What each count is called on the screen.
	 *
	 * @return array<string,string>
	 */
	private static function labels(): array {
		return [
			'links'     => __( 'Связи товаров с карточками', 'woodev-plugin-framework' ),
			'tasks'     => __( 'Задачи на отправку и проверку', 'woodev-plugin-framework' ),
			'postings'  => __( 'Отправления', 'woodev-plugin-framework' ),
			'documents' => __( 'Документы и этикетки', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Counts what the account owns.
	 *
	 * @param int $account_id Account id.
	 * @return array<string,int>
	 */
	private function numbers( int $account_id ): array {
		$numbers = [];

		foreach ( array_keys( self::labels() ) as $key ) {
			$numbers[ $key ] = isset( $this->counters[ $key ] ) ? max( 0, (int) call_user_func( $this->counters[ $key ], $account_id ) ) : 0;
		}

		return $numbers;
	}

	/**
	 * The warnings that apply to the account.
	 *
	 * @param Account $account  Account.
	 * @param int     $postings Postings the account has.
	 * @return string[]
	 */
	private function warnings( Account $account, int $postings ): array {
		$warnings = [];

		if ( $postings > 0 && ! $this->settings->import_enabled( $account ) ) {
			$warnings[] = __( 'У аккаунта выключен импорт заказов, поэтому отправления в плагине — единственный учёт списанных остатков. После удаления отмена старого отправления не вернёт остаток на склад.', 'woodev-plugin-framework' );
		}

		if ( $this->has_push ) {
			$warnings[] = __( 'Адрес уведомлений, зарегистрированный на стороне маркетплейса, может остаться в его личном кабинете. Если плагин не сможет снять его сам, мы скажем об этом после удаления.', 'woodev-plugin-framework' );
		}

		return $warnings;
	}
}
