<?php
/**
 * Markup of the marketplace tab of a product and of the variation panel.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Price\Price_Override;

defined( 'ABSPATH' ) || exit;

/**
 * Markup only: every value arrives ready from {@see Panel_Reader}, every output is escaped here. The merchant is a WordPress novice:
 * short labels, the explanation in the tooltip, no «чекаут», «фреймворк», «вебхук». The marketplace's own name comes from
 * {@see Screen_Names}.
 *
 * The same field set serves three places, told apart by the name base: `woodev_{id}[ACCOUNT][...]` on the tab of a simple product,
 * `woodev_{id}_variation[LOOP][ACCOUNT][...]` in a variation, and the parent block of a variable product (mapping only).
 *
 * @since 2.1.2
 */
final class Panel_View {
	public const WRAPPER_CLASS = 'woodev-mp-block';

	/**
	 * Names.
	 *
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * Tells whether the marketplace has an oversize flag.
	 *
	 * @var bool
	 */
	private $oversize;

	/**
	 * Tells whether the price fields are shown.
	 *
	 * @var bool
	 */
	private $prices;

	/**
	 * Creates the view.
	 *
	 * @since 2.1.2
	 *
	 * @param Screen_Names $names    Names.
	 * @param bool         $oversize Whether the marketplace has an oversize flag ({@see Product_Tab_Facts::supports_oversize()}).
	 * @param bool         $prices   Whether the marketplace takes prices; false hides the price fields.
	 */
	public function __construct( Screen_Names $names, bool $oversize = false, bool $prices = true ) {
		$this->names    = $names;
		$this->oversize = $oversize;
		$this->prices   = $prices;
	}

	/**
	 * Prints the whole tab.
	 *
	 * Keys of `$screen`: `blocks` (one per account, from {@see Panel_Reader}), `nonce` (save nonce), `ops` (`op` => nonce of that action),
	 * `link_scope`, `settings_url`, `mappings_url`, and optionally `extension` (from {@see Product_Extension::screen()}: the adapter's own
	 * fields, printed once after the account blocks; without the key nothing is added).
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $screen What to print.
	 * @return void
	 */
	public function render_tab( array $screen ): void {
		$panel_id = $this->names->tab_key() . '_product_data';

		printf( '<div id="%s" class="panel woocommerce_options_panel woodev-mp-tab">', esc_attr( $panel_id ) );

		if ( [] === $screen['blocks'] ) {
			echo '<div class="options_group"><p class="form-field">' . esc_html( sprintf( /* translators: %s: marketplace name. */ __( 'Нет подключённых аккаунтов %s.', 'woodev-plugin-framework' ), $this->names->name() ) ) . ' <a href="' . esc_url( (string) $screen['settings_url'] ) . '">' . esc_html__( 'Добавить аккаунт', 'woodev-plugin-framework' ) . '</a></p></div></div>';
			return;
		}

		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $this->names->nonce_field() ), esc_attr( (string) $screen['nonce'] ) );

		foreach ( $screen['blocks'] as $block ) {
			$this->block( $block, $screen );
		}

		$this->extension_groups( $screen['extension'] ?? null, 'h3' );

		echo '</div>';
	}

	/**
	 * Prints the marketplace fields inside one variation (WooCommerce prints this after the variation's own attributes).
	 *
	 * Keys of `$screen`: `blocks`, `nonce`, `loop` (position of the variation in the form), `settings_url`, `mappings_url`, and optionally
	 * `extension` (from {@see Product_Extension::screen()} with the loop: the adapter's own fields of the variation).
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $screen What to print.
	 * @return void
	 */
	public function render_variation( array $screen ): void {
		if ( [] === $screen['blocks'] ) {
			return;
		}

		echo '<div class="woodev-mp-variation">';
		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $this->names->nonce_field() ), esc_attr( (string) $screen['nonce'] ) );

		foreach ( $screen['blocks'] as $block ) {
			$base = sprintf( '%s[%d][%d]', $this->names->variation_key(), (int) $screen['loop'], $block['account']->id() );
			$id   = sprintf( '%s_v%d_a%d_', $this->names->tab_key(), (int) $screen['loop'], $block['account']->id() );

			echo '<div class="' . esc_attr( self::WRAPPER_CLASS ) . ' woodev-mp-block--variation">';
			printf( '<h4>%s %s</h4>', esc_html( $this->names->name() . ':' ), $this->badge( $block['badge'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			$this->identifiers( $block, $base, $id );
			$this->validation( $block['validation'], $screen );
			$this->settings_fields( $block, $base, $id, false );
			$this->error_line( $block );
			$this->why( $block );
			echo '</div>';
		}

		$this->extension_groups( $screen['extension'] ?? null, 'h4' );

		echo '</div>';
	}

	/**
	 * Prints the fields an adapter added to the tab ({@see Product_Extension}): a titled block per group, a select per field.
	 *
	 * @param array{groups:\Woodev\Framework\Marketplace\Settings\Extension_Group[],values:array<string,string>,base:string,id:string}|null $extension What {@see Product_Extension::screen()} built, null for none.
	 * @param string                                                                                                                        $heading Tag of the group title.
	 * @return void
	 */
	private function extension_groups( ?array $extension, string $heading ): void {
		foreach ( $extension['groups'] ?? [] as $group ) {
			echo '<div class="options_group ' . esc_attr( self::WRAPPER_CLASS ) . ' woodev-mp-ext">';
			printf( '<%1$s class="woodev-mp-block__title">%2$s</%1$s>', esc_attr( $heading ), esc_html( $group->title() ) );

			foreach ( $group->fields() as $field ) {
				$id      = $extension['id'] . '_' . $field->id();
				$options = '';

				foreach ( $field->options() as $value => $label ) {
					$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( (string) ( $extension['values'][ $field->id() ] ?? '' ), (string) $value, false ), esc_html( $label ) );
				}

				$this->row( $id, $field->label(), sprintf( '<select id="%1$s" name="%2$s" class="select short woodev-mp-wide">%3$s</select>', esc_attr( $id ), esc_attr( $extension['base'] . '[' . $field->id() . ']' ), $options ), $field->tooltip() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}

			echo '</div>';
		}
	}

	/**
	 * Prints one account's block.
	 *
	 * @param array<string,mixed> $block  Block of {@see Panel_Reader}.
	 * @param array<string,mixed> $screen Screen.
	 * @return void
	 */
	private function block( array $block, array $screen ): void {
		$account = $block['account'];
		$base    = sprintf( '%s[%d]', $this->names->post_key(), $account->id() );
		$id      = sprintf( '%s_a%d_', $this->names->tab_key(), $account->id() );

		printf( '<div class="options_group %s" data-product="%d" data-account="%d">', esc_attr( self::WRAPPER_CLASS ), (int) $block['product_id'], (int) $account->id() );
		printf( '<h3 class="woodev-mp-block__title">%s %s</h3>', esc_html( sprintf( /* translators: 1: marketplace name, 2: account name. */ __( 'Аккаунт %1$s: %2$s', 'woodev-plugin-framework' ), $this->names->name(), $account->name() ) ), 'parent' === $block['kind'] ? $this->counts( $block['counts'] ) : $this->badge( $block['badge'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in counts() and badge().

		if ( 'parent' === $block['kind'] ) {
			$this->parent_fields( $block, $base, $id, $screen );
		} else {
			$this->error_line( $block );
			$this->identifiers( $block, $base, $id );
			$this->validation( $block['validation'], $screen );
			$this->settings_fields( $block, $base, $id, true, $screen );
			$this->buttons( $block, $screen );
			$this->hints( $block, $screen );
			$this->why( $block, false );
		}

		echo '</div>';
	}

	/**
	 * Prints the fields and the table of a variable product's block.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param string              $base   Name base.
	 * @param string              $id     Id prefix.
	 * @param array<string,mixed> $screen Screen.
	 * @return void
	 */
	private function parent_fields( array $block, string $base, string $id, array $screen ): void {
		echo '<p class="form-field woodev-mp-intro"><span class="woodev-mp-note">' . esc_html__( 'Это вариативный товар: на маркетплейс уходит каждый вариант отдельной карточкой. Остатки, цены, склад и проверка товара — у каждого варианта, во вкладке «Вариации». Кнопка «Экспортировать» проверит все варианты сразу и покажет, что мешает.', 'woodev-plugin-framework' ) . '</span></p>';

		$this->mapping_field( $block['mapping'], $base, $id, $screen );
		$this->buttons( $block, $screen );
		$this->hints( $block, $screen );
		$this->variation_table( $block );
	}

	/**
	 * Prints the table of the variations.
	 *
	 * @param array<string,mixed> $block Parent block.
	 * @return void
	 */
	private function variation_table( array $block ): void {
		if ( [] === $block['rows'] ) {
			echo '<p class="form-field">' . esc_html__( 'У товара пока нет вариантов, которые продаются на маркетплейсе (виртуальные и скачиваемые не выгружаются).', 'woodev-plugin-framework' ) . '</p>';
			return;
		}

		// The wrapper scrolls on a narrow screen: the cells keep to one line each (an article and its «(будет отправлен)» stay together).
		echo '<div class="woodev-mp-variations-wrap"><table class="widefat striped woodev-mp-variations"><thead><tr>';

		$headings = [
			__( 'Вариант', 'woodev-plugin-framework' ),
			__( 'Состояние', 'woodev-plugin-framework' ),
			__( 'Артикул', 'woodev-plugin-framework' ),
			sprintf( /* translators: %s: marketplace name. */ __( 'Остаток: %s', 'woodev-plugin-framework' ), $this->names->name() ),
			sprintf( /* translators: %s: marketplace name. */ __( 'Цена: %s', 'woodev-plugin-framework' ), $this->names->name() ),
		];

		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $block['rows'] as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( '' !== $row['name'] ? $row['name'] : '#' . $row['id'] ),
				$this->badge( $row['badge'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				esc_html( $row['offer_id'] ) . ( $row['planned'] ? ' <span class="woodev-mp-note">' . esc_html__( '(будет отправлен)', 'woodev-plugin-framework' ) . '</span>' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the same line.
				'' === $row['stock'] ? '—' : esc_html( $row['stock'] ),
				'' === $row['price'] ? '—' : esc_html( trim( $row['price'] . ' ' . $row['currency'] ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Prints the article and the identifiers the marketplace assigned.
	 *
	 * @param array<string,mixed> $block Block.
	 * @param string              $base  Name base.
	 * @param string              $id    Id prefix.
	 * @return void
	 */
	private function identifiers( array $block, string $base, string $id ): void {
		$ids = $block['ids'];

		if ( $ids['editable'] ) {
			$this->row(
				$id . 'offer_id',
				__( 'Артикул', 'woodev-plugin-framework' ),
				sprintf( '<input type="text" class="short" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" maxlength="50" />', esc_attr( $id . 'offer_id' ), esc_attr( $base . '[offer_id]' ), esc_attr( $ids['offer_id'] ), esc_attr( $ids['auto'] ) ),
				__( 'Артикул товара на маркетплейсе. Пока товар не отправлен, его можно изменить; по умолчанию берётся артикул (SKU) товара. После отправки артикул уже не меняется.', 'woodev-plugin-framework' )
			);
		} else {
			$this->row( $id . 'offer_id', __( 'Артикул', 'woodev-plugin-framework' ), '<span>' . esc_html( $ids['offer_id'] ) . '</span>', __( 'Артикул уже отправлен на маркетплейс и больше не меняется.', 'woodev-plugin-framework' ) );
		}

		if ( '' === $ids['summary'] ) {
			return;
		}

		$link = '' !== $ids['card_url'] ? ' · <a href="' . esc_url( $ids['card_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( /* translators: %s: marketplace name. */ __( 'Открыть карточку: %s', 'woodev-plugin-framework' ), $this->names->name() ) ) . '</a>' : '';

		$this->row( $id . 'remote_ids', sprintf( /* translators: %s: marketplace name. */ __( 'Номера: %s', 'woodev-plugin-framework' ), $this->names->name() ), '<span>' . esc_html( $ids['summary'] ) . $link . '</span>', __( 'Эти номера назначает сам маркетплейс, изменить их нельзя.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Prints the result of the validator over one product: a collapsed list under a counted heading, one short line per issue.
	 *
	 * @param array<string,mixed> $validation Validation of the block.
	 * @param array<string,mixed> $screen     Screen.
	 * @return void
	 */
	private function validation( array $validation, array $screen ): void {
		$label = __( 'Проверка', 'woodev-plugin-framework' );

		if ( $validation['failed'] ) {
			$this->row( '', $label, '<span class="woodev-mp-note">' . esc_html__( 'Проверить товар сейчас не удалось. Откройте «Сопоставление» и убедитесь, что категории загружены.', 'woodev-plugin-framework' ) . '</span>', '' );
			return;
		}

		if ( [] === $validation['errors'] && [] === $validation['warnings'] ) {
			$this->row( '', $label, '<span class="woodev-mp-ok">' . esc_html__( 'Товар готов к выгрузке.', 'woodev-plugin-framework' ) . '</span>', __( 'Проверка идёт по данным сайта и не обращается к маркетплейсу.', 'woodev-plugin-framework' ) );
			return;
		}

		// Collapsed: the heading says how many things block the export, the short lines are one click away.
		$summary = [] === $validation['errors']
			? sprintf( /* translators: %d: number of remarks. */ __( 'Проверьте перед выгрузкой (%d)', 'woodev-plugin-framework' ), count( $validation['warnings'] ) )
			: sprintf( /* translators: %d: number of errors. */ __( 'Исправить перед выгрузкой (%d)', 'woodev-plugin-framework' ), count( $validation['errors'] ) );
		$html    = '<details class="woodev-mp-issues"><summary class="woodev-mp-issues__summary' . ( [] === $validation['errors'] ? '' : ' woodev-mp-issues__summary--error' ) . '">' . esc_html( $summary ) . '</summary>';

		foreach ( array_merge( $validation['errors'], $validation['warnings'] ) as $issue ) {
			$html .= '<span class="woodev-mp-issue woodev-mp-issue--' . esc_attr( $issue['level'] ) . '">' . esc_html( $issue['message'] ) . $this->fix_link( (string) ( $issue['fix'] ?? '' ), $screen ) . '</span>';
		}

		$this->row( '', $label, $html . '</details>', __( 'Проверка идёт по данным сайта и не обращается к маркетплейсу. Ошибки нужно исправить до выгрузки.', 'woodev-plugin-framework' ), true );
	}

	/**
	 * Prints the switches, the warehouse, the price fields and the oversize flag.
	 *
	 * @param array<string,mixed> $block        Block.
	 * @param string              $base         Name base.
	 * @param string              $id           Id prefix.
	 * @param bool                $with_mapping Whether the mapping select belongs here.
	 * @param array<string,mixed> $screen       Screen (for the link to the mappings page).
	 * @return void
	 */
	private function settings_fields( array $block, string $base, string $id, bool $with_mapping, array $screen = [] ): void {
		$this->row(
			'',
			__( 'Синхронизация', 'woodev-plugin-framework' ),
			$this->checkbox( $base . '[sync_stock]', $id . 'sync_stock', (bool) $block['sync_stock'], __( 'Остатки', 'woodev-plugin-framework' ) ) . ' ' . $this->checkbox( $base . '[sync_price]', $id . 'sync_price', (bool) $block['sync_price'], __( 'Цены', 'woodev-plugin-framework' ) ),
			__( 'Что сайт отправляет на маркетплейс для этого товара. Выключите, если остатки или цены этого товара вы ведёте на маркетплейсе сами.', 'woodev-plugin-framework' )
		);

		$this->warehouse_field( $block['warehouse'], $base, $id );
		if ( $this->prices ) {
			$this->price_fields( $block['price'], $base, $id );
		}

		if ( $this->oversize ) {
			$this->row( '', __( 'Крупногабарит', 'woodev-plugin-framework' ), $this->checkbox( $base . '[is_oversize]', $id . 'is_oversize', (bool) $block['oversize'], __( 'Крупногабаритный товар', 'woodev-plugin-framework' ) ), __( 'Отметьте, если маркетплейс считает товар крупногабаритным. Для такого товара нужно выбрать склад выше: запасного склада нет.', 'woodev-plugin-framework' ) );
		}

		if ( $with_mapping ) {
			$this->mapping_field( $block['mapping'], $base, $id, $screen );
		}
	}

	/**
	 * Prints the warehouse select.
	 *
	 * @param array<string,mixed> $warehouse Warehouse facts.
	 * @param string              $base      Name base.
	 * @param string              $id        Id prefix.
	 * @return void
	 */
	private function warehouse_field( array $warehouse, string $base, string $id ): void {
		$options = '';

		foreach ( $warehouse['options'] as $option ) {
			$label    = '' === $option['value'] ? ( '' !== $warehouse['default'] ? sprintf( /* translators: %s: warehouse name. */ __( 'Как у аккаунта: %s', 'woodev-plugin-framework' ), $warehouse['default'] ) : __( 'Как у аккаунта (склад не выбран)', 'woodev-plugin-framework' ) ) : $option['label'];
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option['value'] ), selected( (string) $warehouse['selected'], $option['value'], false ), esc_html( $label ) );
		}

		$this->row( $id . 'warehouse_id', __( 'Склад', 'woodev-plugin-framework' ), sprintf( '<select id="%1$s" name="%2$s" class="select short woodev-mp-wide">%3$s</select>', esc_attr( $id . 'warehouse_id' ), esc_attr( $base . '[warehouse_id]' ), $options ), __( 'Склад, на который отправляются остатки этого товара. Оставьте «Как у аккаунта», если товар лежит на основном складе.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Prints the three price fields.
	 *
	 * @param array<string,mixed> $price Price facts.
	 * @param string              $base  Name base.
	 * @param string              $id    Id prefix.
	 * @return void
	 */
	private function price_fields( array $price, string $base, string $id ): void {
		foreach ( $this->price_field_specs() as $name => $field ) {
			$stored  = $price['override'][ $name ] ?? [];
			$kind    = (string) ( $stored['kind'] ?? '' );
			$options = '<option value="">' . esc_html__( 'По правилу', 'woodev-plugin-framework' ) . '</option>';

			foreach ( $field['kinds'] as $value => $label ) {
				$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $kind, $value, false ), esc_html( $label ) );
			}

			$placeholder = '' !== $price['computed'][ $name ] ? $this->money( $price['computed'][ $name ], $price['currency'] ) : (string) $field['empty'];
			$control     = sprintf(
				'<select name="%1$s" class="select short woodev-mp-kind">%2$s</select> <input type="text" inputmode="decimal" class="short woodev-mp-value" name="%3$s" value="%4$s" placeholder="%5$s" />',
				esc_attr( $base . '[price][' . $name . '][kind]' ),
				$options, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_attr( $base . '[price][' . $name . '][value]' ),
				esc_attr( Price_Override::KIND_NONE === $kind ? '' : (string) ( $stored['value'] ?? '' ) ),
				esc_attr( $placeholder )
			);

			$this->row( $id . 'price_' . $name, $field['label'], $control, $field['tip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		if ( '' !== $price['problem'] ) {
			$this->row( '', '', '<span class="description">' . esc_html( $price['problem'] ) . '</span>', '' );
		}
	}

	/**
	 * Declares the three price fields: label, the kinds a merchant may pick, what the box says while the rule gives no figure
	 * for it (`empty`) and the tooltip.
	 *
	 * @return array<string,array{label:string,kinds:array<string,string>,empty:string,tip:string}>
	 */
	private function price_field_specs(): array {
		return [
			Price_Override::FIELD_PRICE     => [
				'label' => sprintf( /* translators: %s: marketplace name. */ __( 'Цена для %s', 'woodev-plugin-framework' ), $this->names->name() ),
				'kinds' => [
					Price_Override::KIND_FIXED   => __( 'Своя сумма', 'woodev-plugin-framework' ),
					Price_Override::KIND_PERCENT => __( 'Наценка, %', 'woodev-plugin-framework' ),
					Price_Override::KIND_NONE    => __( 'Без наценки', 'woodev-plugin-framework' ),
				],
				'empty' => '',
				'tip'   => __( 'По умолчанию цена на маркетплейсе считается по правилу из настроек аккаунта или профиля. Здесь можно задать свою цену именно для этого товара. В сером — цена, которая получится по правилу.', 'woodev-plugin-framework' ),
			],
			Price_Override::FIELD_OLD_PRICE => [
				'label' => __( 'Старая цена', 'woodev-plugin-framework' ),
				'kinds' => [
					Price_Override::KIND_FIXED   => __( 'Своя сумма', 'woodev-plugin-framework' ),
					Price_Override::KIND_PERCENT => __( 'Выше цены на, %', 'woodev-plugin-framework' ),
					Price_Override::KIND_NONE    => __( 'Не показывать', 'woodev-plugin-framework' ),
				],
				'empty' => __( 'Не показывается', 'woodev-plugin-framework' ),
				'tip'   => __( 'Зачёркнутая цена на маркетплейсе. «Не показывать» убирает её у этого товара.', 'woodev-plugin-framework' ),
			],
			Price_Override::FIELD_MIN_PRICE => [
				'label' => __( 'Минимальная цена', 'woodev-plugin-framework' ),
				'kinds' => [
					Price_Override::KIND_FIXED   => __( 'Своя сумма', 'woodev-plugin-framework' ),
					Price_Override::KIND_PERCENT => __( '% от цены', 'woodev-plugin-framework' ),
				],
				'empty' => __( 'Не задана', 'woodev-plugin-framework' ),
				'tip'   => __( 'Ниже этой цены маркетплейс не опустит цену в акциях.', 'woodev-plugin-framework' ),
			],
		];
	}

	/**
	 * Formats an amount the way WooCommerce does (the shop's separators and currency position), as plain text for a placeholder.
	 *
	 * @param string $amount   Amount.
	 * @param string $currency Currency code of the account.
	 * @return string E.g. «99,00 ₽».
	 */
	private function money( string $amount, string $currency ): string {
		$text = wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => $currency ] ) );

		return trim( str_replace( "\u{A0}", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Prints the explicit mapping select, with the link to the mappings page on a line under it.
	 *
	 * @param array<string,mixed> $mapping Mapping facts.
	 * @param string              $base    Name base.
	 * @param string              $id      Id prefix.
	 * @param array<string,mixed> $screen  Screen (for the link to the mappings page).
	 * @return void
	 */
	private function mapping_field( array $mapping, string $base, string $id, array $screen ): void {
		$default = '' !== $mapping['resolved'] ? sprintf( /* translators: %s: name of the mapping. */ __( 'По категории: %s', 'woodev-plugin-framework' ), $mapping['resolved'] ) : __( 'По категории: сопоставления нет', 'woodev-plugin-framework' );
		$options = '<option value="">' . esc_html( $default ) . '</option>';

		foreach ( $mapping['options'] as $option ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option['value'] ), selected( (string) $mapping['selected'], $option['value'], false ), esc_html( $option['label'] ) );
		}

		// The link goes under the select, on a line of its own: beside it, it squeezed the select's text.
		$link = isset( $screen['mappings_url'] ) ? '<span class="woodev-mp-row-link"><a href="' . esc_url( (string) $screen['mappings_url'] ) . '">' . esc_html__( 'Открыть «Сопоставление»', 'woodev-plugin-framework' ) . '</a></span>' : '';

		$this->row( $id . 'mapping_id', __( 'Сопоставление', 'woodev-plugin-framework' ), sprintf( '<select id="%1$s" name="%2$s" class="select short woodev-mp-wide">%3$s</select>%4$s', esc_attr( $id . 'mapping_id' ), esc_attr( $base . '[mapping_id]' ), $options, $link ), __( 'Какой категории маркетплейса и какому набору характеристик соответствует товар. Обычно подходит «По категории»: сопоставление берётся из категории товара на сайте.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Prints the action buttons as one group in one row.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param array<string,mixed> $screen Screen (`ops`).
	 * @return void
	 */
	private function buttons( array $block, array $screen ): void {
		$labels  = $this->button_labels( $block, (string) ( $screen['link_scope'] ?? '' ) );
		$tips    = $this->button_tips();
		$confirm = [
			'archive' => sprintf( /* translators: %s: marketplace name. */ __( 'Точно архивировать на %s? Нажмите ещё раз.', 'woodev-plugin-framework' ), $this->names->name() ),
			'detach'  => __( 'Точно снять связь? Нажмите ещё раз.', 'woodev-plugin-framework' ),
		];

		// One group of adjacent buttons, one row, never two. A button is an icon and its words; the stylesheet shows only the icon while the row is too
		// narrow for the words (a container query), so the name goes in `aria-label` and the explanation in the tooltip.
		echo '<p class="form-field woodev-mp-buttons"><span class="woodev-mp-buttons__row" role="group" aria-label="' . esc_attr__( 'Действия с карточкой', 'woodev-plugin-framework' ) . '">';

		foreach ( $labels as $op => $label ) {
			if ( ! isset( $screen['ops'][ $op ] ) ) {
				continue; // The user may not press it: the guard would refuse, so the screen does not offer it.
			}

			printf(
				'<button type="button" class="button woodev-mp-btn%1$s" %2$s data-confirm="%3$s" title="%4$s" aria-label="%5$s">%6$s<span class="woodev-mp-btn__text">%5$s</span></button>',
				'export' === $op ? ' button-primary' : '',
				$this->op_attributes( $op, $block, $screen ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in op_attributes().
				esc_attr( $confirm[ $op ] ?? '' ),
				esc_attr( $label . '. ' . $tips[ $op ] ),
				esc_attr( $label ),
				$this->op_icon( $op, $this->export_is_new( $block ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
			);
		}

		echo '</span><span class="woodev-mp-result woodev-mp-note" role="status" aria-live="polite"></span></p>';
	}

	private function op_attributes( string $op, array $block, array $screen ): string {
		return sprintf(
			'data-woodev-mp-op="%1$s" data-action="%2$s" data-nonce="%3$s" data-product="%4$d" data-account="%5$d"',
			esc_attr( $op ),
			esc_attr( $this->names->product_action() ),
			esc_attr( (string) $screen['ops'][ $op ] ),
			(int) $block['product_id'],
			(int) $block['account']->id()
		);
	}

	/**
	 * Tells whether the export button is «Экспортировать» (the card has not been sent) and not «Обновить карточку».
	 *
	 * @param array<string,mixed> $block Block.
	 * @return bool
	 */
	private function export_is_new( array $block ): bool {
		return 'offer' === $block['kind'] ? Card_State_Badge::is_unsent( $block['state'] ) : true;
	}

	/**
	 * Returns the icon of a button: an inline SVG that draws with the button's text colour, so it needs no icon font.
	 *
	 * @param string $op     Action.
	 * @param bool   $is_new Whether the export button sends a card for the first time.
	 * @return string Static markup.
	 */
	private function op_icon( string $op, bool $is_new ): string {
		$paths = [
			'export'  => $is_new
				? '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>'
				: '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
			'link'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'archive' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
			'detach'  => '<path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M5.17 11.75l-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="8" y1="2" x2="8" y2="5"/><line x1="2" y1="8" x2="5" y2="8"/><line x1="16" y1="19" x2="16" y2="22"/><line x1="19" y1="16" x2="22" y2="16"/>',
		];

		return '<svg class="woodev-mp-btn__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $op ] ?? '' ) . '</svg>';
	}

	/**
	 * Picks the buttons a block offers and their labels.
	 *
	 * @param array<string,mixed> $block Block.
	 * @param string              $scope Name of the product's category, '' when it has none.
	 * @return array<string,string> `op` => label.
	 */
	private function button_labels( array $block, string $scope ): array {
		$is_offer = 'offer' === $block['kind'];
		$is_new   = $this->export_is_new( $block );
		$labels   = [
			'export' => $is_new ? __( 'Экспортировать', 'woodev-plugin-framework' ) : __( 'Обновить карточку', 'woodev-plugin-framework' ),
			'link'   => '' !== $scope ? __( 'Связать категорию по артикулу', 'woodev-plugin-framework' ) : __( 'Связать все товары по артикулу', 'woodev-plugin-framework' ),
		];

		if ( $is_offer ? $block['can_archive'] : $block['any_created'] ) {
			$labels['archive'] = sprintf( /* translators: %s: marketplace name. */ __( 'Архивировать на %s', 'woodev-plugin-framework' ), $this->names->name() );
		}

		if ( $is_offer ? $block['can_detach'] : $block['any_linked'] ) {
			$labels['detach'] = __( 'Снять связь', 'woodev-plugin-framework' );
		}

		return $labels;
	}

	/**
	 * Returns the tooltips of the buttons.
	 *
	 * @return array<string,string>
	 */
	private function button_tips(): array {
		return [
			'export'  => __( 'Проверит товар и поставит его в очередь на отправку на маркетплейс. Результат появится в состоянии выше через минуту-две.', 'woodev-plugin-framework' ),
			'link'    => __( 'Ищет в кабинете маркетплейса карточки с таким же артикулом, как у товаров, и связывает их с сайтом. Работает не с одним этим товаром, а со всеми товарами категории (или магазина).', 'woodev-plugin-framework' ),
			'archive' => __( 'Остаток карточки на маркетплейсе станет нулевым, и карточка уйдёт в архив. Товар на сайте не меняется.', 'woodev-plugin-framework' ),
			'detach'  => __( 'Сайт забудет связь с карточкой маркетплейса. Сама карточка не удаляется и не меняется.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Prints the help under the buttons as one collapsed «Подсказки» block: that the buttons work with the saved product, what «Связать по
	 * артикулу» works on (a category-wide search, not a search for this one product) and, when the reasons are hidden, how to see them.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param array<string,mixed> $screen Screen (`ops`, `link_scope`).
	 * @return void
	 */
	private function hints( array $block, array $screen ): void {
		$scope = (string) ( $screen['link_scope'] ?? '' );
		$hints = [
			sprintf( /* translators: %s: name of a button. */ __( 'Кнопки работают с уже сохранённым товаром: если меняли поля выше, сначала сохраните товар («Обновить» справа), затем нажмите «%s».', 'woodev-plugin-framework' ), $this->button_labels( $block, $scope )['export'] ),
		];

		if ( isset( $screen['ops']['link'] ) ) {
			$hints[] = '' !== $scope
				? sprintf( /* translators: %s: category name. */ __( 'Кнопка «Связать категорию по артикулу» ищет карточки маркетплейса для всех товаров категории «%s», а не только для этого товара.', 'woodev-plugin-framework' ), $scope )
				: __( 'У товара нет категории, поэтому кнопка «Связать все товары по артикулу» ищет карточки маркетплейса для всех товаров магазина, а не только для этого.', 'woodev-plugin-framework' );
		}

		if ( 0 !== ( $block['link_id'] ?? 0 ) && ! ( $block['reasons']['visible'] ?? true ) ) {
			$hints[] = __( 'Включите «Логирование» в настройках, чтобы увидеть, почему остатки и цены этого товара не отправляются.', 'woodev-plugin-framework' );
		}

		echo '<fieldset class="form-field woodev-mp-hints"><details><summary>' . esc_html__( 'Подсказки', 'woodev-plugin-framework' ) . '</summary>';

		foreach ( $hints as $hint ) {
			echo '<span class="woodev-mp-note">' . esc_html( $hint ) . '</span>';
		}

		echo '</details></fieldset>';
	}

	/**
	 * Prints the marketplace's last error for the product (always shown: it is the state of what the merchant is looking at).
	 *
	 * @param array<string,mixed> $block Block.
	 * @return void
	 */
	private function error_line( array $block ): void {
		if ( '' === $block['remote_error'] ) {
			return;
		}

		$this->row( '', sprintf( /* translators: %s: marketplace name. */ __( 'Ответ: %s', 'woodev-plugin-framework' ), $this->names->name() ), '<span class="woodev-mp-error">' . nl2br( esc_html( $block['remote_error'] ) ) . '</span>', __( 'Текст ошибки так, как его прислал маркетплейс.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Prints «Почему не синхронизируется». While «Логирование» is off there is nothing to show: a tab says how to turn it on in its «Подсказки»
	 * ({@see Panel_View::hints()}); a variation has no such block and keeps the line.
	 *
	 * @param array<string,mixed> $block     Block.
	 * @param bool                $hint_line Whether to print the line that says how to see the reasons when they are hidden.
	 * @return void
	 */
	private function why( array $block, bool $hint_line = true ): void {
		if ( 0 === $block['link_id'] ) {
			return;
		}

		$label = __( 'Почему не синхронизируется', 'woodev-plugin-framework' );

		if ( ! $block['reasons']['visible'] ) {
			if ( $hint_line ) {
				$this->row( '', $label, '<span class="woodev-mp-note">' . esc_html__( 'Включите «Логирование» в настройках, чтобы увидеть разбор причин', 'woodev-plugin-framework' ) . '</span>', '' );
			}

			return;
		}

		if ( [] === $block['reasons']['list'] ) {
			$this->row( '', $label, '<span class="woodev-mp-ok">' . esc_html__( 'Причин не найдено: остатки и цены этого товара отправляются.', 'woodev-plugin-framework' ) . '</span>', '' );
			return;
		}

		$html = '<span class="woodev-mp-issues">';

		foreach ( $block['reasons']['list'] as $reason ) {
			$html .= '<span class="woodev-mp-issue woodev-mp-issue--error">' . esc_html( $reason ) . '</span>';
		}

		$this->row( '', $label, $html . '</span>', __( 'Причины перечислены по порядку: сначала самая важная. Исправьте первую и обновите страницу.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Prints one `form-field` row, the tooltip inside the label.
	 *
	 * @param string $target  Id of the control the label belongs to, '' for none.
	 * @param string $label   Label text.
	 * @param string $control Escaped markup of the control.
	 * @param string $tip         Tooltip text.
	 * @param bool   $collapsible Whether the control holds a `<details>`: the row is then a `fieldset`.
	 * @return void
	 */
	private function row( string $target, string $label, string $control, string $tip, bool $collapsible = false ): void {
		// The «?» sits inside the label, so it stays beside the label's text whatever the control's own width is.
		$tooltip = '' === $tip ? '' : sprintf( '<span class="woocommerce-help-tip" tabindex="0" aria-label="%1$s" data-tip="%1$s"></span>', esc_attr( $tip ) );

		// A `<details>` cannot sit in a `<p>` (the parser would close the paragraph before it), so a row that holds one is a `fieldset` with a `legend`:
		// WooCommerce styles `fieldset.form-field` and `legend` exactly like `p.form-field` and `label`.
		printf(
			$collapsible ? '<fieldset class="form-field"><legend>%2$s%3$s</legend>%4$s</fieldset>' : '<p class="form-field"><label%1$s>%2$s%3$s</label>%4$s</p>',
			'' === $target ? '' : ' for="' . esc_attr( $target ) . '"', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on this line.
			esc_html( $label ),
			$tooltip, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			$control // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		);
	}

	/**
	 * Builds a checkbox that posts `0` when it is off (an unchecked box posts nothing).
	 *
	 * @param string $name    Field name.
	 * @param string $id      Field id.
	 * @param bool   $checked Whether it is on.
	 * @param string $label   Label.
	 * @return string Escaped markup.
	 */
	private function checkbox( string $name, string $id, bool $checked, string $label ): string {
		return sprintf(
			'<input type="hidden" name="%1$s" value="0" /><label class="woodev-mp-check"><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $id ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Builds a state badge.
	 *
	 * @param array{label:string,tone:string} $badge Badge.
	 * @return string Escaped markup.
	 */
	private function badge( array $badge ): string {
		return sprintf( '<span class="woodev-mp-badge woodev-mp-badge--%1$s">%2$s</span>', esc_attr( $badge['tone'] ), esc_html( $badge['label'] ) );
	}

	/**
	 * Builds the summary of the states of the variations.
	 *
	 * @param array<string,int> $counts Label => count.
	 * @return string Escaped markup.
	 */
	private function counts( array $counts ): string {
		$parts = [];

		foreach ( $counts as $label => $count ) {
			$parts[] = esc_html( $label . ': ' . $count );
		}

		return '<span class="woodev-mp-badge woodev-mp-badge--neutral">' . ( [] === $parts ? esc_html__( 'Нет вариантов', 'woodev-plugin-framework' ) : implode( ' · ', $parts ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Builds the «Исправить» link that says where an issue is fixed.
	 *
	 * @param string              $fix    `Validation_Report::FIX_*` or ''.
	 * @param array<string,mixed> $screen Screen.
	 * @return string Escaped markup, with a leading space; '' when there is nowhere to send the merchant.
	 */
	private function fix_link( string $fix, array $screen ): string {
		$targets = [
			'mapping'    => (string) ( $screen['mappings_url'] ?? '' ),
			'dictionary' => (string) ( $screen['mappings_url'] ?? '' ),
			'profile'    => (string) ( $screen['mappings_url'] ?? '' ),
			'account'    => (string) ( $screen['settings_url'] ?? '' ),
		];

		if ( '' === ( $targets[ $fix ] ?? '' ) ) {
			return '';
		}

		return ' <a href="' . esc_url( $targets[ $fix ] ) . '">' . esc_html__( 'Исправить', 'woodev-plugin-framework' ) . '</a>';
	}
}
