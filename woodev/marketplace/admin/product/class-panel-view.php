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
	 * `link_scope`, `settings_url`, `mappings_url`.
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

		echo '</div>';
	}

	/**
	 * Prints the marketplace fields inside one variation (WooCommerce prints this after the variation's own attributes).
	 *
	 * Keys of `$screen`: `blocks`, `nonce`, `loop` (position of the variation in the form), `settings_url`, `mappings_url`.
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

		echo '</div>';
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
			$this->why( $block );
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
		echo '<p class="form-field"><span class="description">' . esc_html__( 'Это вариативный товар: на маркетплейс уходит каждый вариант отдельной карточкой. Остатки, цены, склад и проверка товара — у каждого варианта, во вкладке «Вариации». Кнопка «Экспортировать» проверит все варианты сразу и покажет, что мешает.', 'woodev-plugin-framework' ) . '</span></p>';

		$this->mapping_field( $block['mapping'], $base, $id, $screen );
		$this->buttons( $block, $screen );
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

		echo '<table class="widefat striped woodev-mp-variations"><thead><tr>';

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
				esc_html( $row['offer_id'] ) . ( $row['planned'] ? ' <span class="description">' . esc_html__( '(будет отправлен)', 'woodev-plugin-framework' ) . '</span>' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the same line.
				'' === $row['stock'] ? '—' : esc_html( $row['stock'] ),
				'' === $row['price'] ? '—' : esc_html( trim( $row['price'] . ' ' . $row['currency'] ) )
			);
		}

		echo '</tbody></table>';
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
	 * Prints the result of the validator over one product.
	 *
	 * @param array<string,mixed> $validation Validation of the block.
	 * @param array<string,mixed> $screen     Screen.
	 * @return void
	 */
	private function validation( array $validation, array $screen ): void {
		$label = __( 'Проверка', 'woodev-plugin-framework' );

		if ( $validation['failed'] ) {
			$this->row( '', $label, '<span class="description">' . esc_html__( 'Проверить товар сейчас не удалось. Откройте «Сопоставление» и убедитесь, что категории загружены.', 'woodev-plugin-framework' ) . '</span>', '' );
			return;
		}

		if ( [] === $validation['errors'] && [] === $validation['warnings'] ) {
			$this->row( '', $label, '<span class="woodev-mp-ok">✓ ' . esc_html__( 'Товар готов к выгрузке.', 'woodev-plugin-framework' ) . '</span>', __( 'Проверка идёт по данным сайта и не обращается к маркетплейсу.', 'woodev-plugin-framework' ) );
			return;
		}

		$html = '<span class="woodev-mp-issues">';

		foreach ( array_merge( $validation['errors'], $validation['warnings'] ) as $issue ) {
			$html .= '<span class="woodev-mp-issue woodev-mp-issue--' . esc_attr( $issue['level'] ) . '">' . esc_html( $issue['message'] ) . $this->fix_link( (string) ( $issue['fix'] ?? '' ), $screen ) . '</span>';
		}

		$this->row( '', $label, $html . '</span>', __( 'Проверка идёт по данным сайта и не обращается к маркетплейсу. Ошибки нужно исправить до выгрузки.', 'woodev-plugin-framework' ) );
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

			$placeholder = '' !== $price['computed'][ $name ] ? trim( $price['computed'][ $name ] . ' ' . $price['currency'] ) : '';
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
	 * Declares the three price fields: label, the kinds a merchant may pick and the tooltip.
	 *
	 * @return array<string,array{label:string,kinds:array<string,string>,tip:string}>
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
				'tip'   => __( 'По умолчанию цена на маркетплейсе считается по правилу из настроек аккаунта или профиля. Здесь можно задать свою цену именно для этого товара. В сером — цена, которая получится по правилу.', 'woodev-plugin-framework' ),
			],
			Price_Override::FIELD_OLD_PRICE => [
				'label' => __( 'Старая цена', 'woodev-plugin-framework' ),
				'kinds' => [
					Price_Override::KIND_FIXED   => __( 'Своя сумма', 'woodev-plugin-framework' ),
					Price_Override::KIND_PERCENT => __( 'Выше цены на, %', 'woodev-plugin-framework' ),
					Price_Override::KIND_NONE    => __( 'Не показывать', 'woodev-plugin-framework' ),
				],
				'tip'   => __( 'Зачёркнутая цена на маркетплейсе. «Не показывать» убирает её у этого товара.', 'woodev-plugin-framework' ),
			],
			Price_Override::FIELD_MIN_PRICE => [
				'label' => __( 'Минимальная цена', 'woodev-plugin-framework' ),
				'kinds' => [
					Price_Override::KIND_FIXED   => __( 'Своя сумма', 'woodev-plugin-framework' ),
					Price_Override::KIND_PERCENT => __( '% от цены', 'woodev-plugin-framework' ),
				],
				'tip'   => __( 'Ниже этой цены маркетплейс не опустит цену в акциях.', 'woodev-plugin-framework' ),
			],
		];
	}

	/**
	 * Prints the explicit mapping select.
	 *
	 * @param array<string,mixed> $mapping Mapping facts.
	 * @param string              $base    Name base.
	 * @param string              $id      Id prefix.
	 * @param array<string,mixed> $screen  Screen (for the link to the mappings page).
	 * @return void
	 */
	private function mapping_field( array $mapping, string $base, string $id, array $screen ): void {
		$default = '' !== $mapping['resolved'] ? sprintf( /* translators: %s: name of the mapping. */ __( 'По категории (сейчас: %s)', 'woodev-plugin-framework' ), $mapping['resolved'] ) : __( 'По категории (сопоставления для категории нет)', 'woodev-plugin-framework' );
		$options = '<option value="">' . esc_html( $default ) . '</option>';

		foreach ( $mapping['options'] as $option ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option['value'] ), selected( (string) $mapping['selected'], $option['value'], false ), esc_html( $option['label'] ) );
		}

		$link = isset( $screen['mappings_url'] ) ? ' <a href="' . esc_url( (string) $screen['mappings_url'] ) . '">' . esc_html__( 'Открыть «Сопоставление»', 'woodev-plugin-framework' ) . '</a>' : '';

		$this->row( $id . 'mapping_id', __( 'Сопоставление', 'woodev-plugin-framework' ), sprintf( '<select id="%1$s" name="%2$s" class="select short woodev-mp-wide">%3$s</select>%4$s', esc_attr( $id . 'mapping_id' ), esc_attr( $base . '[mapping_id]' ), $options, $link ), __( 'Какой категории маркетплейса и какому набору характеристик соответствует товар. Обычно подходит «По категории»: сопоставление берётся из категории товара на сайте.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Prints the action buttons.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param array<string,mixed> $screen Screen (`ops`).
	 * @return void
	 */
	private function buttons( array $block, array $screen ): void {
		$account = $block['account'];
		$labels  = $this->button_labels( $block, (string) ( $screen['link_scope'] ?? '' ) );
		$tips    = $this->button_tips();
		$confirm = [
			'archive' => sprintf( /* translators: %s: marketplace name. */ __( 'Точно архивировать: %s? Нажмите ещё раз.', 'woodev-plugin-framework' ), $this->names->name() ),
			'detach'  => __( 'Точно снять связь? Нажмите ещё раз.', 'woodev-plugin-framework' ),
		];

		echo '<p class="form-field woodev-mp-buttons"><label></label><span class="woodev-mp-buttons__row">';

		foreach ( $labels as $op => $label ) {
			if ( ! isset( $screen['ops'][ $op ] ) ) {
				continue; // The user may not press it: the guard would refuse, so the screen does not offer it.
			}

			printf(
				'<button type="button" class="button%1$s" data-woodev-mp-op="%2$s" data-action="%3$s" data-nonce="%4$s" data-product="%5$d" data-account="%6$d" title="%7$s" data-confirm="%8$s">%9$s</button> ',
				'export' === $op ? ' button-primary' : '',
				esc_attr( $op ),
				esc_attr( $this->names->product_action() ),
				esc_attr( (string) $screen['ops'][ $op ] ),
				(int) $block['product_id'],
				(int) $account->id(),
				esc_attr( $tips[ $op ] ),
				esc_attr( $confirm[ $op ] ?? '' ),
				esc_html( $label )
			);
		}

		echo '</span><span class="woodev-mp-result description" role="status" aria-live="polite"></span></p>';
		echo '<p class="form-field"><span class="description">' . esc_html__( 'Кнопки работают с уже сохранённым товаром: сначала нажмите «Обновить», если меняли поля выше.', 'woodev-plugin-framework' ) . '</span></p>';

		if ( isset( $screen['ops']['link'] ) ) {
			$this->link_scope_line( (string) ( $screen['link_scope'] ?? '' ) );
		}
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
		$is_new   = $is_offer ? Card_State_Badge::is_unsent( $block['state'] ) : true;
		$labels   = [
			'export' => $is_new ? __( 'Экспортировать', 'woodev-plugin-framework' ) : __( 'Обновить карточку', 'woodev-plugin-framework' ),
			'link'   => '' !== $scope ? __( 'Связать категорию по артикулу', 'woodev-plugin-framework' ) : __( 'Связать все товары по артикулу', 'woodev-plugin-framework' ),
		];

		if ( $is_offer ? $block['can_archive'] : $block['any_created'] ) {
			$labels['archive'] = sprintf( /* translators: %s: marketplace name. */ __( 'Архивировать: %s', 'woodev-plugin-framework' ), $this->names->name() );
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
			'link'    => __( 'Ищет в кабинете маркетплейса карточки с таким же артикулом, как у товаров, и связывает их с сайтом. Работает не с одним этим товаром, а со всеми товарами категории (или магазина). На маркетплейсе ничего не меняется.', 'woodev-plugin-framework' ),
			'archive' => __( 'Остаток карточки на маркетплейсе станет нулевым, и карточка уйдёт в архив. Товар на сайте не меняется.', 'woodev-plugin-framework' ),
			'detach'  => __( 'Сайт забудет связь с карточкой маркетплейса. Сама карточка не удаляется и не меняется.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Says, in plain sight, what «Связать по артикулу» works on: it is a category-wide search, not a search for this one product.
	 *
	 * @param string $scope Name of the product's category, '' when it has none.
	 * @return void
	 */
	private function link_scope_line( string $scope ): void {
		$text = '' !== $scope
			? sprintf( /* translators: %s: category name. */ __( 'Кнопка «Связать категорию по артикулу» ищет карточки маркетплейса для всех товаров категории «%s», а не только для этого товара.', 'woodev-plugin-framework' ), $scope )
			: __( 'У товара нет категории, поэтому кнопка «Связать все товары по артикулу» ищет карточки маркетплейса для всех товаров магазина, а не только для этого.', 'woodev-plugin-framework' );

		echo '<p class="form-field"><span class="description">' . esc_html( $text ) . '</span></p>';
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
	 * Prints «Почему не синхронизируется», or the line that says how to see it. Only while «Логирование» is on.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return void
	 */
	private function why( array $block ): void {
		if ( 0 === $block['link_id'] ) {
			return;
		}

		$label = __( 'Почему не синхронизируется', 'woodev-plugin-framework' );

		if ( ! $block['reasons']['visible'] ) {
			$this->row( '', $label, '<span class="description">' . esc_html__( 'Включите «Логирование» в настройках, чтобы увидеть разбор причин', 'woodev-plugin-framework' ) . '</span>', '' );
			return;
		}

		if ( [] === $block['reasons']['list'] ) {
			$this->row( '', $label, '<span class="woodev-mp-ok">✓ ' . esc_html__( 'Причин не найдено: остатки и цены этого товара отправляются.', 'woodev-plugin-framework' ) . '</span>', '' );
			return;
		}

		$html = '<span class="woodev-mp-issues">';

		foreach ( $block['reasons']['list'] as $reason ) {
			$html .= '<span class="woodev-mp-issue woodev-mp-issue--error">' . esc_html( $reason ) . '</span>';
		}

		$this->row( '', $label, $html . '</span>', __( 'Причины перечислены по порядку: сначала самая важная. Исправьте первую и обновите страницу.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Prints one `form-field` row.
	 *
	 * @param string $target  Id of the control the label belongs to, '' for none.
	 * @param string $label   Label text.
	 * @param string $control Escaped markup of the control.
	 * @param string $tip     Tooltip text.
	 * @return void
	 */
	private function row( string $target, string $label, string $control, string $tip ): void {
		$tooltip = '' === $tip ? '' : sprintf( '<span class="woocommerce-help-tip" tabindex="0" aria-label="%1$s" data-tip="%1$s"></span>', esc_attr( $tip ) );

		printf(
			'<p class="form-field"><label%1$s>%2$s</label>%3$s%4$s</p>',
			'' === $target ? '' : ' for="' . esc_attr( $target ) . '"', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on this line.
			esc_html( $label ),
			$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
			$tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
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
	 * Builds the link that says where an issue is fixed.
	 *
	 * @param string              $fix    `Validation_Report::FIX_*` or ''.
	 * @param array<string,mixed> $screen Screen.
	 * @return string Escaped markup, with a leading space; '' when there is nowhere to send the merchant.
	 */
	private function fix_link( string $fix, array $screen ): string {
		$targets = [
			'mapping'    => [ (string) ( $screen['mappings_url'] ?? '' ), __( 'Сопоставление', 'woodev-plugin-framework' ) ],
			'dictionary' => [ (string) ( $screen['mappings_url'] ?? '' ), __( 'Сопоставление', 'woodev-plugin-framework' ) ],
			'profile'    => [ (string) ( $screen['mappings_url'] ?? '' ), __( 'Профили', 'woodev-plugin-framework' ) ],
			'account'    => [ (string) ( $screen['settings_url'] ?? '' ), __( 'Настройки', 'woodev-plugin-framework' ) ],
		];

		if ( ! isset( $targets[ $fix ] ) || '' === $targets[ $fix ][0] ) {
			return '';
		}

		return ' <a href="' . esc_url( $targets[ $fix ][0] ) . '">' . esc_html( sprintf( /* translators: %s: name of the page. */ __( 'Исправить: %s', 'woodev-plugin-framework' ), $targets[ $fix ][1] ) ) . '</a>';
	}
}
