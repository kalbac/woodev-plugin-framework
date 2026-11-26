<?php
/**
 * What deleting an account would remove, and what the merchant must know before agreeing.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only: four counts (links, tasks, postings, documents), the sentence that products and WooCommerce orders stay, the note that
 * an enabled account is switched off first, and the warnings that apply to this account. Changes nothing and calls no marketplace.
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
	 * The marketplace's word for a posting.
	 *
	 * @var Posting_Noun
	 */
	private $noun;

	/**
	 * Creates the preview.
	 *
	 * @since 2.1.5
	 * @since 2.1.23 Takes the posting noun.
	 *
	 * @param array<string,callable> $counters Keys `links`, `tasks`, `postings`, `documents`, each `function ( int $account_id ): int`.
	 * @param Effective_Settings     $settings Resolver of `import_enabled`.
	 * @param bool                   $has_push Whether the marketplace has push notifications.
	 * @param Posting_Noun|null      $noun     The marketplace's word for a posting; null for «отправление».
	 */
	public function __construct( array $counters, Effective_Settings $settings, bool $has_push, ?Posting_Noun $noun = null ) {
		$this->counters = $counters;
		$this->settings = $settings;
		$this->has_push = $has_push;
		$this->noun     = $noun ?? Posting_Noun::shipment();
	}

	/**
	 * Describes the deletion of an account.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account.
	 * @return array{counts:array<int,array{key:string,label:string,count:int}>,kept:string,enabled_note:string,warnings:string[]}
	 */
	public function describe( Account $account ): array {
		$numbers = $this->numbers( $account->id() );
		$counts  = [];

		foreach ( $this->labels() as $key => $label ) {
			$counts[] = [
				'key'   => $key,
				'label' => $label,
				'count' => $numbers[ $key ],
			];
		}

		return [
			'counts'       => $counts,
			'kept'         => __( 'Товары и заказы WooCommerce остаются в магазине: заказы удаляются отдельно.', 'woodev-plugin-framework' ),
			'enabled_note' => $account->is_enabled() ? $this->enabled_note() : '',
			'warnings'     => $this->warnings( $account, $numbers['postings'] ),
		];
	}

	/**
	 * What the merchant is told about an account that is still switched on: it is switched off, and what that stops.
	 *
	 * @return string
	 */
	private function enabled_note(): string {
		/* translators: %s: «about several postings», preposition included: «об отправлениях». */
		return sprintf( __( 'Аккаунт включён. Выключить и удалить? Перед удалением плагин выключит аккаунт: остатки, цены и заказы по нему перестанут синхронизироваться. Связи товаров с карточками и записи %s будут удалены.', 'woodev-plugin-framework' ), $this->noun->about_many() );
	}

	/**
	 * What each count is called on the screen.
	 *
	 * @return array<string,string>
	 */
	private function labels(): array {
		return [
			'links'     => __( 'Связи товаров с карточками', 'woodev-plugin-framework' ),
			'tasks'     => __( 'Задачи на отправку и проверку', 'woodev-plugin-framework' ),
			'postings'  => Posting_Noun::capitalised( $this->noun->many() ),
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

		foreach ( array_keys( $this->labels() ) as $key ) {
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
			/* translators: 1: the marketplace's word for several postings, «отправления», 2: the word for one in the genitive, «отправления». */
			$warnings[] = sprintf( __( 'У аккаунта выключен импорт заказов, поэтому %1$s в плагине — единственный учёт списанных остатков. После удаления отмена старого %2$s не вернёт остаток на склад.', 'woodev-plugin-framework' ), $this->noun->many(), $this->noun->of_one() );
		}

		if ( $this->has_push ) {
			$warnings[] = __( 'Адрес уведомлений, зарегистрированный на стороне маркетплейса, может остаться в его личном кабинете. Если плагин не сможет снять его сам, мы скажем об этом после удаления.', 'woodev-plugin-framework' );
		}

		return $warnings;
	}
}
