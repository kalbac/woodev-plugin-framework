<?php
/**
 * Gathers what the marketplace tab of a product shows, from our own tables.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Shared\Sync_Reason_Text;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;
use Woodev\Framework\Marketplace\Diagnostics\Why_Not_Syncing;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Facts;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The read side of the product tab. **It never calls the marketplace**: the state comes from the link row, the validator result from
 * the cached catalogue and the product itself, the remote stock and price from `sync_state`.
 *
 * The result is plain arrays, ready for {@see Panel_View}; one call gives one account's block of one product or variation.
 *
 * @since 2.1.2
 */
final class Panel_Reader {
	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Export inputs.
	 *
	 * @var Export_Context_Builder
	 */
	private $contexts;

	/**
	 * The marketplace's facts about a product (which products can be exported).
	 *
	 * @var Export_Facts
	 */
	private $facts;

	/**
	 * The validator over the adapter's rules.
	 *
	 * @var Export_Validator
	 */
	private $validator;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Warehouses.
	 *
	 * @var Warehouse_Options
	 */
	private $warehouses;

	/**
	 * Intents and readbacks.
	 *
	 * @var Sync_State_Store
	 */
	private $sync_state;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Licence.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * «Логирование».
	 *
	 * @var Logging
	 */
	private $logging;

	/**
	 * The chosen articles.
	 *
	 * @var Pinned_Offer_Id
	 */
	private $pinned;

	/**
	 * The marketplace's article rules.
	 *
	 * @var Article_Policy
	 */
	private $article_policy;

	/**
	 * The marketplace-specific answers of the tab.
	 *
	 * @var Product_Tab_Facts
	 */
	private $tab_facts;

	/**
	 * Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Wpdb_Link_Store        $links          Links.
	 * @param Export_Context_Builder $contexts       Export inputs.
	 * @param Export_Facts           $facts          The marketplace's facts about a product.
	 * @param Export_Validator       $validator      Validator over the adapter's rules.
	 * @param Wpdb_Mapping_Store     $mappings       Mappings.
	 * @param Warehouse_Options      $warehouses     Warehouses.
	 * @param Sync_State_Store       $sync_state     Intents and readbacks.
	 * @param Wpdb_Account_Store     $accounts       Accounts.
	 * @param License_Gate           $license        Licence.
	 * @param Logging                $logging        «Логирование».
	 * @param Pinned_Offer_Id        $pinned         The chosen articles.
	 * @param Article_Policy         $article_policy The marketplace's article rules.
	 * @param Product_Tab_Facts      $tab_facts      The marketplace-specific answers of the tab.
	 * @param callable|null          $clock          `function (): int`; the system clock when null.
	 */
	public function __construct( Wpdb_Link_Store $links, Export_Context_Builder $contexts, Export_Facts $facts, Export_Validator $validator, Wpdb_Mapping_Store $mappings, Warehouse_Options $warehouses, Sync_State_Store $sync_state, Wpdb_Account_Store $accounts, License_Gate $license, Logging $logging, Pinned_Offer_Id $pinned, Article_Policy $article_policy, Product_Tab_Facts $tab_facts, ?callable $clock = null ) {
		$this->links          = $links;
		$this->contexts       = $contexts;
		$this->facts          = $facts;
		$this->validator      = $validator;
		$this->mappings       = $mappings;
		$this->warehouses     = $warehouses;
		$this->sync_state     = $sync_state;
		$this->accounts       = $accounts;
		$this->license        = $license;
		$this->logging        = $logging;
		$this->pinned         = $pinned;
		$this->article_policy = $article_policy;
		$this->tab_facts      = $tab_facts;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Reads the block of a simple product or a variation.
	 *
	 * @since 2.1.2
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Simple product or variation.
	 * @return array<string,mixed>
	 */
	public function offer_block( Account $account, \WC_Product $product ): array {
		$row      = $this->links->find( $account->id(), (int) $product->get_id() );
		$record   = null !== $row && $row->is_offer() ? $row : null;
		$link_id  = null === $record ? 0 : (int) $record->link_id();
		$state    = null === $record ? Link_Record::STATE_UNLINKED : $record->card_state();
		$shown    = $record ?? Link_Record::offer(
			$account->id(),
			(int) $product->get_id(),
			(int) $product->get_parent_id(),
			'',
			[
				'sync_stock' => 1,
				'sync_price' => 1,
			]
		);
		$overview = $link_id > 0 ? ( $this->sync_state->overview( $account->id(), [ $link_id ] )[ $link_id ] ?? null ) : null;

		return [
			'kind'        => 'offer',
			'product_id'  => (int) $product->get_id(),
			'account'     => $account,
			'link_id'     => $link_id,
			'state'       => $state,
			'badge'       => Card_State_Badge::of( $state ),
			'remote_error' => $link_id > 0 ? $this->links->last_error( $link_id )['text'] : '',
			'ids'         => $this->identifiers( $account, $product, $record, $shown ),
			'validation'  => $this->validation( $account, $product, $record ),
			'sync_stock'  => $shown->sync_stock(),
			'sync_price'  => $shown->sync_price(),
			'oversize'    => $shown->is_oversize(),
			'warehouse'   => $this->warehouse( $account, $shown ),
			'price'       => $this->price( $account, $product, $shown ),
			'mapping'     => $this->mapping( $account, $product, $shown ),
			'sync_facts'  => $overview,
			'reasons'     => $this->reasons( $account, $record, $overview ),
			'can_detach'  => null !== $record && Link_Record::STATE_UNLINKED !== $state,
			'can_archive' => Link_Record::STATE_CREATED === $state,
		];
	}

	/**
	 * Reads the block of a variable product: its own mapping and the table of the variations.
	 *
	 * The validator is not run over all variations here: each run builds the variation's siblings too, so a product with N variations
	 * would cost N × N mapping resolutions on every page load. Each variation's own panel shows its result (WooCommerce loads those
	 * fifteen at a time), and «Экспортировать» answers with the grouped report of the whole product.
	 *
	 * @since 2.1.2
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Variable product.
	 * @return array<string,mixed>
	 */
	public function parent_block( Account $account, \WC_Product $product ): array {
		$row        = $this->links->find( $account->id(), (int) $product->get_id() );
		$parent_row = null !== $row && ! $row->is_offer() ? $row : Link_Record::parent_row( $account->id(), (int) $product->get_id() );
		$children   = [];
		$records    = [];
		$link_ids   = [];

		foreach ( array_map( 'intval', (array) $product->get_children() ) as $child_id ) {
			$child = $this->contexts->product( $child_id );

			if ( null === $child || ! $this->facts->is_exportable( $child ) ) {
				continue;
			}

			$children[ $child_id ] = $child;
			$record                = $this->links->find( $account->id(), $child_id );
			$records[ $child_id ]  = null !== $record && $record->is_offer() ? $record : null;
			$link_ids[]            = null === $records[ $child_id ] ? 0 : (int) $records[ $child_id ]->link_id();
		}

		$overview = $this->sync_state->overview( $account->id(), $link_ids );

		return [
			'kind'        => 'parent',
			'product_id'  => (int) $product->get_id(),
			'account'     => $account,
			'mapping'     => $this->mapping( $account, $product, $parent_row ),
			'counts'      => $this->state_counts( $records ),
			'rows'        => $this->variation_rows( $account, $children, $records, $overview ),
			'any_created' => $this->any_in_state(
				$records,
				static function ( Link_Record $record ): bool {
					return Link_Record::STATE_CREATED === $record->card_state();
				}
			),
			'any_linked'  => $this->any_in_state(
				$records,
				static function ( Link_Record $record ): bool {
					return Link_Record::STATE_UNLINKED !== $record->card_state();
				}
			),
		];
	}

	/**
	 * Tells whether the diagnostic list may be shown: only while «Логирование» is on.
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function explains(): bool {
		return $this->logging->is_enabled();
	}

	/**
	 * Tells whether any variation row satisfies a test.
	 *
	 * @param array<int,Link_Record|null> $records Rows of the variations, null where a variation has none.
	 * @param callable                    $test    `function ( Link_Record ): bool`.
	 * @return bool
	 */
	private function any_in_state( array $records, callable $test ): bool {
		foreach ( $records as $record ) {
			if ( null !== $record && $test( $record ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Names what the article section shows.
	 *
	 * @param Account          $account Account.
	 * @param \WC_Product      $product Product.
	 * @param Link_Record|null $record  Its stored offer row.
	 * @param Link_Record      $shown   The stored row, or the one a new product would get.
	 * @return array<string,mixed>
	 */
	private function identifiers( Account $account, \WC_Product $product, ?Link_Record $record, Link_Record $shown ): array {
		$editable = Link_Settings_Writer::article_is_editable( $record );
		$pinned   = $editable ? $this->pinned->of( $product, $account->id() ) : null;
		$auto     = $this->automatic_article( $product );

		return [
			'editable' => $editable,
			'offer_id' => $editable ? (string) $pinned : (string) $shown->offer_id(),
			'auto'     => $auto,
			'summary'  => $this->tab_facts->identifier_summary( $shown ),
			'card_url' => $this->tab_facts->card_url( $shown ),
		];
	}

	/**
	 * Works out the article a product gets when the merchant chooses none: its SKU, normalised, else the policy's fallback.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return string
	 */
	private function automatic_article( \WC_Product $product ): string {
		$sku        = $this->article_policy->normalise( (string) $product->get_sku() );
		$normalised = '' !== $sku ? $sku : $this->article_policy->fallback( (int) $product->get_id() );

		return $normalised;
	}

	/**
	 * Runs the validator over the product, with the cached catalogue only.
	 *
	 * @param Account          $account Account.
	 * @param \WC_Product      $product Product or variation.
	 * @param Link_Record|null $record  Its stored offer row.
	 * @return array{errors:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,failed:bool}
	 */
	private function validation( Account $account, \WC_Product $product, ?Link_Record $record ): array {
		$report = $this->report( $account, $product, $record );

		return null === $report
			? [
				'errors'   => [],
				'warnings' => [],
				'failed'   => true,
			]
			: [
				'errors'   => $report->errors(),
				'warnings' => $report->warnings(),
				'failed'   => false,
			];
	}

	/**
	 * Builds the validator report; null when the inputs cannot be built.
	 *
	 * @param Account          $account Account.
	 * @param \WC_Product      $product Product or variation.
	 * @param Link_Record|null $record  Its stored offer row.
	 * @return Validation_Report|null
	 */
	private function report( Account $account, \WC_Product $product, ?Link_Record $record ): ?Validation_Report {
		try {
			$context = $this->contexts->build( $account, $product, $record );

			return $this->validator->validate( $product, $context->mapping(), $context->profile(), $context->raw_context() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Reads the warehouse choice: the options (the first one is «как у аккаунта»), the selected id and the account's default name.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $shown   Row.
	 * @return array<string,mixed>
	 */
	private function warehouse( Account $account, Link_Record $shown ): array {
		$listed   = $this->warehouses->for_account( $account->id() );
		$selected = $shown->warehouse_id();
		$options  = [
			[
				'value' => '',
				'label' => '',
			],
		];

		foreach ( $listed as $id => $label ) {
			$options[] = [
				'value' => (string) $id,
				'label' => (string) $label,
			];
		}

		if ( null !== $selected && ! isset( $listed[ (string) $selected ] ) ) {
			$options[] = [
				'value' => (string) $selected,
				'label' => '#' . $selected,
			];
		}

		$default = (int) $account->setting( 'warehouse_id', 0 );
		$name    = $default > 0 && isset( $listed[ (string) $default ] ) ? (string) $listed[ (string) $default ] : '';

		return [
			'selected' => $selected,
			'options'  => $options,
			'default'  => $default > 0 ? ( '' !== $name ? $name : sprintf( /* translators: %d: warehouse id. */ __( 'склад %d', 'woodev-plugin-framework' ), $default ) ) : '',
		];
	}

	/**
	 * Reads the price override and the prices the rule alone would give (the placeholders of the fields).
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @param Link_Record $shown   Row.
	 * @return array<string,mixed>
	 */
	private function price( Account $account, \WC_Product $product, Link_Record $shown ): array {
		$computed = [
			'price'     => '',
			'old_price' => '',
			'min_price' => '',
		];
		$problem  = '';

		try {
			$pair = $this->contexts->price_pair( $account, $product, $shown->with( [ 'price_rule_override' => null ] ) );

			if ( null === $pair ) {
				$problem = __( 'У товара нет цены, поэтому цену для маркетплейса посчитать нельзя.', 'woodev-plugin-framework' );
			} else {
				$computed['price']     = $pair->price()->to_string();
				$computed['old_price'] = null === $pair->old_price() ? '' : $pair->old_price()->to_string();
				$computed['min_price'] = null === $pair->min_price() ? '' : $pair->min_price()->to_string();
			}
		} catch ( \InvalidArgumentException $e ) {
			$problem = __( 'Цену для маркетплейса посчитать не удалось: проверьте правило цены и курс валюты в профиле.', 'woodev-plugin-framework' );
		}

		return [
			'override' => (array) $shown->price_rule_override(),
			'computed' => $computed,
			'currency' => strtoupper( (string) $account->setting( 'currency', 'RUB' ) ),
			'problem'  => $problem,
		];
	}

	/**
	 * Reads the mapping choice: the stored one, and what «по категории» resolves to now.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product, variation or variable parent.
	 * @param Link_Record $shown   Row.
	 * @return array<string,mixed>
	 */
	private function mapping( Account $account, \WC_Product $product, Link_Record $shown ): array {
		$options  = [];
		$selected = $shown->mapping_id();
		$listed   = [];

		foreach ( $this->mappings->all_term_bound() as $mapping ) {
			$listed[ (int) $mapping['mapping_id'] ] = true;
			$options[]                              = [
				'value' => (string) $mapping['mapping_id'],
				'label' => (string) $mapping['name'],
			];
		}

		if ( null !== $selected && ! isset( $listed[ $selected ] ) ) {
			$options[] = [
				'value' => (string) $selected,
				'label' => __( 'Настроено для этого товара', 'woodev-plugin-framework' ),
			];
		}

		return [
			'selected' => $selected,
			'options'  => $options,
			'resolved' => $this->resolved_mapping_name( $account, $product, $shown ),
		];
	}

	/**
	 * Names the mapping that applies to the product when none is chosen explicitly.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Link_Record $shown   Row.
	 * @return string Empty when no mapping applies.
	 */
	private function resolved_mapping_name( Account $account, \WC_Product $product, Link_Record $shown ): string {
		try {
			$id = $this->contexts->build( $account, $product, $shown->with( [ 'mapping_id' => null ] ) )->mapping()->id();
		} catch ( \Throwable $e ) {
			return '';
		}

		$found = null === $id ? null : $this->mappings->find( $id );

		return null === $found ? '' : (string) $found['name'];
	}

	/**
	 * Collects the reasons a link does not sync while «Логирование» is on.
	 *
	 * @param Account                  $account  Account.
	 * @param Link_Record|null         $record   Stored offer row.
	 * @param array<string,mixed>|null $overview Facts of `sync_state` for the link.
	 * @return array{visible:bool,list:string[]} `visible` is false while «Логирование» is off or the product has no link.
	 */
	private function reasons( Account $account, ?Link_Record $record, ?array $overview ): array {
		$link = null === $record ? null : $this->links->by_product( $account->id(), $record->wc_product_id() );

		if ( null === $link || ! $this->explains() ) {
			return [
				'visible' => false,
				'list'    => [],
			];
		}

		$health  = $this->accounts->health( $account->id() );
		$now     = (int) call_user_func( $this->clock );
		$next    = max( (int) ( $overview['stock']['next_attempt_at'] ?? 0 ), (int) ( $overview['price']['next_attempt_at'] ?? 0 ) );
		$error   = $this->links->last_error( (int) $record->link_id() )['text'];
		$facts   = [
			'license_ok'           => $this->license->is_in_force(),
			'health'               => null === $health ? Health_Result::OK : $health->status(),
			'default_warehouse_id' => $account->setting( 'warehouse_id' ),
			'retry_after_seconds'  => $next > $now ? $next - $now : null,
			'last_error'           => self::first_filled( $error, (string) ( $overview['stock']['last_error'] ?? '' ), (string) ( $overview['price']['last_error'] ?? '' ) ),
		];
		$reasons = Why_Not_Syncing::reasons( $link, $account, $facts );
		$list    = Sync_Reason_Text::sentences( $reasons );

		if ( $this->tab_facts->supports_oversize() && $record->is_oversize() && null === $record->warehouse_id() ) {
			$list = self::with_oversize_reason( $reasons, $list );
		}

		return [
			'visible' => true,
			'list'    => $list,
		];
	}

	/**
	 * Picks the first text that is not empty: a stock row with no error must not hide a price row that has one.
	 *
	 * @param string ...$texts Candidates, most important first.
	 * @return string
	 */
	private static function first_filled( string ...$texts ): string {
		foreach ( $texts as $text ) {
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * Adds the oversize reason after the reasons about the warehouse and before the ones about the rate limit and the last error,
	 * which stay last.
	 *
	 * @param Sync_Reason[] $reasons Reasons of {@see Why_Not_Syncing}.
	 * @param string[]      $list    Their sentences, in the same order.
	 * @return string[]
	 */
	private static function with_oversize_reason( array $reasons, array $list ): array {
		$sentence = __( 'Крупногабаритный товар без крупногабаритного склада — выберите склад для этого товара на вкладке товара.', 'woodev-plugin-framework' );

		foreach ( array_values( $reasons ) as $index => $reason ) {
			if ( in_array( $reason->code(), [ Sync_Reason::RATE_LIMITED, Sync_Reason::LAST_ERROR ], true ) ) {
				array_splice( $list, $index, 0, [ $sentence ] );

				return $list;
			}
		}

		$list[] = $sentence;

		return $list;
	}

	/**
	 * Counts the variations by the name of their state.
	 *
	 * @param array<int,Link_Record|null> $records Rows of the variations, null where a variation has none.
	 * @return array<string,int> Label => count.
	 */
	private function state_counts( array $records ): array {
		$counts = [];

		foreach ( $records as $record ) {
			$label            = Card_State_Badge::of( null === $record ? Link_Record::STATE_UNLINKED : $record->card_state() )['label'];
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Builds the table rows of the variations: state, article, stock and price on the marketplace.
	 *
	 * @param Account                        $account  Account.
	 * @param array<int,\WC_Product>         $children Variations by id.
	 * @param array<int,Link_Record|null>    $records  Their rows.
	 * @param array<int,array<string,mixed>> $overview `sync_state` facts by link id.
	 * @return array<int,array<string,mixed>>
	 */
	private function variation_rows( Account $account, array $children, array $records, array $overview ): array {
		$rows = [];

		foreach ( $children as $child_id => $child ) {
			$record = $records[ $child_id ];
			$state  = null === $record ? Link_Record::STATE_UNLINKED : $record->card_state();
			$facts  = null === $record ? null : ( $overview[ (int) $record->link_id() ] ?? null );
			$stock  = $facts['stock'] ?? null;
			$sent   = $facts['price']['sent'] ?? null;
			$rows[] = [
				'id'       => $child_id,
				'name'     => function_exists( 'wc_get_formatted_variation' ) && $child instanceof \WC_Product_Variation ? wp_strip_all_tags( (string) wc_get_formatted_variation( $child, true, false ) ) : '',
				'badge'    => Card_State_Badge::of( $state ),
				'offer_id' => null === $record || null === $record->offer_id() ? $this->contexts->offer_id( $account, $child, $record ?? Link_Record::offer( $account->id(), $child_id, (int) $child->get_parent_id(), '' ) ) : (string) $record->offer_id(),
				'planned'  => null === $record || null === $record->frozen_offer_id(),
				'stock'    => null !== $stock && null !== $stock['present'] ? (string) ( (int) $stock['present'] - (int) ( $stock['reserved'] ?? 0 ) ) : '',
				'price'    => is_array( $sent ) && isset( $sent['price'] ) ? (string) $sent['price'] : '',
				'currency' => is_array( $sent ) && isset( $sent['currency_code'] ) ? (string) $sent['currency_code'] : '',
			];
		}

		return $rows;
	}
}
