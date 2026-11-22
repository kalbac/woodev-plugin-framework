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
	 * The most buttons that still show their words; a group of more shows only the icons.
	 */
	public const LABELLED_MAX = 3;

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
	 * Whether the adapter can change the article of a card that was sent.
	 *
	 * @var bool
	 */
	private $renames;

	/**
	 * Creates the view.
	 *
	 * @since 2.1.2
	 *
	 * @param Screen_Names $names    Names.
	 * @param bool         $oversize Whether the marketplace has an oversize flag ({@see Product_Tab_Facts::supports_oversize()}).
	 * @param bool         $prices   Whether the marketplace takes prices; false hides the price fields.
	 * @param bool         $renames  Whether the adapter changes the article of a sent card ({@see \Woodev\Framework\Marketplace\Flows\Offer_Id_Renamer}).
	 */
	public function __construct( Screen_Names $names, bool $oversize = false, bool $prices = true, bool $renames = false ) {
		$this->names    = $names;
		$this->oversize = $oversize;
		$this->prices   = $prices;
		$this->renames  = $renames;
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

		// One account: no account name anywhere. Two or more: one select, which the script moves into the header of «Product data», and one block
		// shown at a time (all of them stay in the form, so «Обновить» saves every account's fields).
		$several = count( $screen['blocks'] ) > 1;

		// The one shown at first: `default_account` (the first enabled account that is connected), else the first block.
		$default = $this->shown_account( $screen );

		if ( $several ) {
			$this->account_switch( $screen['blocks'], $default );
		}

		foreach ( $screen['blocks'] as $block ) {
			$this->block( $block, $screen, $several, $default === (int) $block['account']->id() );
		}

		$this->extension_groups( $screen['extension'] ?? null, 'h3' );

		echo '</div>';
	}

	public function render_variation( array $screen ): void {
		if ( [] === $screen['blocks'] ) {
			return;
		}

		$several = count( $screen['blocks'] ) > 1;
		$shown   = $several ? $this->shown_account( $screen ) : 0;

		echo '<div class="woodev-mp-variation">';
		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $this->names->nonce_field() ), esc_attr( (string) $screen['nonce'] ) );

		foreach ( $screen['blocks'] as $block ) {
			$base = sprintf( '%s[%d][%d]', $this->names->variation_key(), (int) $screen['loop'], $block['account']->id() );
			$id   = sprintf( '%s_v%d_a%d_', $this->names->tab_key(), (int) $screen['loop'], $block['account']->id() );

			// Every account's block stays in the form (so «Обновить» saves each); the account select of «Product data» shows one of them.
			echo '<div class="' . esc_attr( self::WRAPPER_CLASS ) . ' woodev-mp-block--variation"' . $this->account_part_attributes( $block, $shown, $several ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in account_part_attributes().
			printf( '<h4>%s %s</h4>', esc_html( ( $several ? $this->names->name() . ' · ' . $block['account']->name() : $this->names->name() ) . ':' ), $this->state_badge( $block['badge'], $this->problems( $block, $screen ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
			$this->identifiers( $block, $base, $id );
			$this->settings_fields( $block, $base, $id, false );
			$this->variation_buttons( $block, $screen );
			echo '</div>';
		}

		$this->extension_groups( $screen['extension'] ?? null, 'h4' );

		echo '</div>';
	}

	public function render_variation_header( array $screen ): void {
		$several = count( $screen['blocks'] ) > 1;
		$shown   = $several ? $this->shown_account( $screen ) : 0;

		// One slot per marketplace (a name and its badge on one baseline, with room to its right): another marketplace's plugin prints its own
		// slot after this one, so the header holds as many as there are marketplaces. With several accounts every account has a slot and the
		// account select of «Product data» leaves visible the one of the picked account, so the header shows one badge per marketplace.
		foreach ( $screen['blocks'] as $block ) {
			printf(
				'<span class="woodev-mp-header-state"%3$s><span class="woodev-mp-header-state__name">%1$s</span>%2$s</span>',
				esc_html( $several ? $this->names->name() . ' · ' . $block['account']->name() : $this->names->name() ),
				$this->state_badge( $block['badge'], $this->problems( $block, $screen ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in state_badge().
				$this->account_part_attributes( $block, $shown, $several ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in account_part_attributes().
			);
		}
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
	 * Prints one account's block: the title with the state badge, the «?», then the fields.
	 *
	 * @param array<string,mixed> $block   Block of {@see Panel_Reader}.
	 * @param array<string,mixed> $screen  Screen.
	 * @param bool                $several Whether the product has more than one account: only then the title names the account.
	 * @param bool                $shown   Whether the block is the one shown at first; the others are hidden until the account select picks them.
	 * @return void
	 */
	private function block( array $block, array $screen, bool $several = false, bool $shown = true ): void {
		$account = $block['account'];
		$base    = sprintf( '%s[%d]', $this->names->post_key(), $account->id() );
		$id      = sprintf( '%s_a%d_', $this->names->tab_key(), $account->id() );
		$title   = $several ? sprintf( /* translators: 1: marketplace name, 2: account name. */ __( 'Аккаунт %1$s: %2$s', 'woodev-plugin-framework' ), $this->names->name(), $account->name() ) : $this->names->name();

		printf( '<div class="options_group %s" data-product="%d" data-account="%d"%s>', esc_attr( self::WRAPPER_CLASS ), (int) $block['product_id'], (int) $account->id(), $shown ? '' : ' hidden' );
		printf( '<h3 class="woodev-mp-block__title">%s %s</h3>', esc_html( $title ), 'parent' === $block['kind'] ? $this->counts( $block['counts'] ) : $this->state_badge( $block['badge'], $this->problems( $block, $screen ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in counts() and state_badge().
		$this->help( $block, $screen );

		if ( 'parent' === $block['kind'] ) {
			$this->parent_fields( $block, $base, $id, $screen );
		} else {
			$this->identifiers( $block, $base, $id );
			$this->settings_fields( $block, $base, $id, true, $screen );
			$this->buttons( $block, $screen );
		}

		echo '</div>';
	}

	/**
	 * Prints the select of the account the tab shows. It is printed at the top of the tab; the script moves it into the header of «Product data»
	 * (right of Virtual / Downloadable) and shows it while this tab or the «Variations» tab is open. The same account is shown in both.
	 *
	 * @param array<int,array<string,mixed>> $blocks  Blocks, one per account.
	 * @param int                            $default Account shown until the merchant picks another.
	 * @return void
	 */
	private function account_switch( array $blocks, int $default ): void {
		$id      = $this->names->tab_key() . '_account';
		$options = '';

		foreach ( $blocks as $block ) {
			$options .= sprintf( '<option value="%1$d"%3$s>%2$s</option>', (int) $block['account']->id(), esc_html( $block['account']->name() ), selected( $default, (int) $block['account']->id(), false ) );
		}

		printf(
			'<div class="woodev-mp-account-switch" data-remember="%1$s" data-marketplace="%4$s"><label for="%1$s">%2$s</label><select id="%1$s" class="woodev-mp-account-select">%3$s</select></div>',
			esc_attr( $id ),
			esc_html( sprintf( /* translators: %s: marketplace name. */ __( 'Аккаунт %s:', 'woodev-plugin-framework' ), $this->names->name() ) ),
			$options, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_attr( $this->names->id() )
		);
	}

	/**
	 * Tells which account is shown at first: the screen's `default_account` (the first enabled account that is connected), else the first block.
	 * The tab and the variations name it the same way, so the select and every account part agree before the script runs.
	 *
	 * @param array<string,mixed> $screen Screen with at least one block.
	 * @return int Account id.
	 */
	private function shown_account( array $screen ): int {
		$default = (int) ( $screen['default_account'] ?? 0 );

		return $default > 0 ? $default : (int) reset( $screen['blocks'] )['account']->id();
	}

	/**
	 * The attributes that tie a part of a variation (its block in the opened variation, its slot in the collapsed header) to one account, so that
	 * the account select of «Product data» can show the picked account's part and hide the others, as it does for the blocks of the tab. A product
	 * with one account has no select and nothing to hide.
	 *
	 * @param array<string,mixed> $block   Block of the account.
	 * @param int                 $shown   Account shown at first.
	 * @param bool                $several Whether the product has more than one account.
	 * @return string Attributes with a leading space, escaped; empty with one account.
	 */
	private function account_part_attributes( array $block, int $shown, bool $several ): string {
		if ( ! $several ) {
			return '';
		}

		$account = (int) $block['account']->id();

		return sprintf( ' data-marketplace="%s" data-account="%d"%s', esc_attr( $this->names->id() ), $account, $shown === $account ? '' : ' hidden' );
	}

	private function parent_fields( array $block, string $base, string $id, array $screen ): void {
		echo '<p class="form-field woodev-mp-intro"><span class="woodev-mp-note">' . esc_html__( 'Остатки, цены и проверка — у каждого варианта, во вкладке «Вариации».', 'woodev-plugin-framework' ) . '</span></p>';

		$this->mapping_field( $block['mapping'], $base, $id, $screen );
		$this->buttons( $block, $screen );
		$this->variation_table( $block, $screen );
	}

	private function article_note( array $row ): string {
		$pending = (string) ( $row['pending'] ?? '' );
		$failed  = ! empty( $row['rename_failed'] );

		if ( '' === $pending ) {
			return '';
		}

		return ' <span class="woodev-mp-note' . ( $failed ? ' woodev-mp-error' : '' ) . '">' . esc_html(
			$failed
				? sprintf( /* translators: %s: article. */ __( '(не удалось сменить на «%s»)', 'woodev-plugin-framework' ), $pending )
				: sprintf( /* translators: %s: article. */ __( '(меняется на «%s»)', 'woodev-plugin-framework' ), $pending )
		) . '</span>';
	}

	private function variation_table( array $block, array $screen ): void {
		if ( [] === $block['rows'] ) {
			echo '<p class="form-field">' . esc_html__( 'У товара пока нет вариантов, которые продаются на маркетплейсе (виртуальные и скачиваемые не выгружаются).', 'woodev-plugin-framework' ) . '</p>';
			return;
		}

		// Ticking, the select above the table and the buttons of a row exist only when the tab was given the variations' actions.
		$facts = $screen['variations'] ?? null;

		printf(
			'<div class="woodev-mp-variants" data-account="%1$d" data-product="%2$d" data-action="%3$s" data-nonce="%4$s">',
			(int) $block['account']->id(),
			(int) $block['product_id'],
			esc_attr( (string) ( $facts['action'] ?? '' ) ),
			esc_attr( (string) ( $facts['nonce'] ?? '' ) )
		);

		if ( null !== $facts ) {
			$this->variants_toolbar( $facts, sprintf( '%s_a%d_bulk', $this->names->tab_key(), $block['account']->id() ) );
		}

		// The wrapper scrolls on a narrow screen: the cells keep to one line each.
		echo '<div class="woodev-mp-variations-wrap"><table class="woodev-mp-variations"><thead><tr>' . $this->variation_head( null !== $facts ) . '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in variation_head().

		foreach ( $block['rows'] as $row ) {
			echo '<tr>' . $this->variation_row( $row, $block, $facts ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in variation_row().
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Prints the line above the table: «Выберите действие» + «Применить» (the same actions as the Variations tab's «Bulk actions») and the
	 * line that answers both the select and the buttons of the rows.
	 *
	 * @param array{select:array<string,string>} $facts The variations' actions ({@see \Woodev\Framework\Marketplace\Admin\Product\Variation_Bulk_Actions::panel_facts()}).
	 * @param string                             $id    Id of the select.
	 * @return void
	 */
	private function variants_toolbar( array $facts, string $id ): void {
		echo '<div class="woodev-mp-variants__toolbar">';

		if ( [] !== $facts['select'] ) {
			$options = '<option value="">' . esc_html__( 'Выберите действие', 'woodev-plugin-framework' ) . '</option>';

			foreach ( $facts['select'] as $action => $label ) {
				$options .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( (string) $action ), esc_html( $label ) );
			}

			printf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" class="woodev-mp-variants__select">%3$s</select> <button type="button" class="button woodev-mp-variants__apply">%4$s</button>',
				esc_attr( $id ),
				esc_html__( 'Действие над отмеченными вариантами', 'woodev-plugin-framework' ),
				$options, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html__( 'Применить', 'woodev-plugin-framework' )
			);
		}

		echo '<span class="woodev-mp-result woodev-mp-note" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Builds the heading cells of the table of the variants.
	 *
	 * @param bool $with_actions Whether the table has the tick column and the «Действия» column.
	 * @return string Escaped markup.
	 */
	private function variation_head( bool $with_actions ): string {
		$name  = $this->names->name();
		$cells = $with_actions ? '<td class="woodev-mp-variations__check"><input type="checkbox" class="woodev-mp-variants__all" aria-label="' . esc_attr__( 'Отметить все варианты', 'woodev-plugin-framework' ) . '" /></td>' : '';
		$cells .= '<th scope="col" class="woodev-mp-variations__image"><span class="screen-reader-text">' . esc_html__( 'Изображение', 'woodev-plugin-framework' ) . '</span></th>';

		foreach ( [ __( 'Вариант', 'woodev-plugin-framework' ), __( 'Состояние', 'woodev-plugin-framework' ), __( 'Артикул', 'woodev-plugin-framework' ) ] as $heading ) {
			$cells .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		$cells .= '<th scope="col">' . esc_html__( 'Остаток', 'woodev-plugin-framework' ) . $this->help_tip( sprintf( /* translators: %s: marketplace name. */ __( 'Слева — остаток на сайте, справа — на %s. Прочерк — данных пока нет.', 'woodev-plugin-framework' ), $name ) ) . '</th>';
		$cells .= '<th scope="col">' . esc_html__( 'Цена', 'woodev-plugin-framework' ) . $this->help_tip( sprintf( /* translators: %s: marketplace name. */ __( 'Слева — цена на сайте, справа — цена, которую %s получил в последний раз. Прочерк — данных пока нет.', 'woodev-plugin-framework' ), $name ) ) . '</th>';

		return $cells . ( $with_actions ? '<th scope="col" class="woodev-mp-variations__actions">' . esc_html__( 'Действия', 'woodev-plugin-framework' ) . '</th>' : '' );
	}

	/**
	 * Builds the cells of one variant: the tick, the picture, the name, the state, the article, the stock and the price as «сайт / маркетплейс»,
	 * and the buttons.
	 *
	 * @param array<string,mixed>      $row   Row of {@see Panel_Reader}.
	 * @param array<string,mixed>      $block Parent block.
	 * @param array<string,mixed>|null $facts The variations' actions, null for none.
	 * @return string Escaped markup.
	 */
	private function variation_row( array $row, array $block, ?array $facts ): string {
		$name  = '' !== $row['name'] ? $row['name'] : '#' . $row['id'];
		$image = (string) ( $row['image'] ?? '' );
		$cells = '';

		if ( null !== $facts ) {
			$cells .= sprintf( '<td class="woodev-mp-variations__check"><input type="checkbox" class="woodev-mp-variants__check" value="%1$d" aria-label="%2$s" /></td>', (int) $row['id'], esc_attr( sprintf( /* translators: %s: name of a variation. */ __( 'Отметить вариант «%s»', 'woodev-plugin-framework' ), $name ) ) );
		}

		$cells .= '<td class="woodev-mp-variations__image">' . ( '' === $image ? '' : sprintf( '<img src="%s" alt="" width="36" height="36" loading="lazy" />', esc_url( $image ) ) ) . '</td>';
		// The words in `data-label` are the headings of a row that is stacked on a narrow panel (the heading row is hidden then).
		$cells .= '<td class="woodev-mp-variations__name">' . esc_html( $name ) . '</td>';
		$cells .= '<td class="woodev-mp-variations__state">' . $this->badge( $row['badge'] ) . '</td>';
		$cells .= '<td class="woodev-mp-variations__article" data-label="' . esc_attr__( 'Артикул', 'woodev-plugin-framework' ) . '">' . esc_html( $row['offer_id'] ) . ( $row['planned'] ? $this->help_tip( __( 'Артикул будет отправлен на маркетплейс вместе с карточкой.', 'woodev-plugin-framework' ) ) : '' ) . $this->article_note( $row ) . '</td>';
		$cells .= '<td class="woodev-mp-variations__stock" data-label="' . esc_attr__( 'Остаток', 'woodev-plugin-framework' ) . '">' . esc_html( $this->site_and_remote( (string) ( $row['site_stock'] ?? '' ), (string) $row['stock'] ) ) . '</td>';
		$cells .= '<td class="woodev-mp-variations__price" data-label="' . esc_attr__( 'Цена', 'woodev-plugin-framework' ) . '">' . esc_html( $this->site_and_remote( (string) ( $row['site_price'] ?? '' ), (string) $row['price'] ) ) . '</td>';

		if ( null !== $facts ) {
			$group  = $this->button_group(
				$this->labels_for( (bool) ( $row['is_new'] ?? true ), ! empty( $row['can_archive'] ), ! empty( $row['can_detach'] ) ),
				(bool) ( $row['is_new'] ?? true ),
				$this->variation_attributes( (int) $row['id'], (int) $block['product_id'], (int) $block['account']->id(), $facts ),
				'woodev-mp-buttons__row--icons'
			);
			$cells .= '<td class="woodev-mp-variations__actions">' . $group . '</td>';
		}

		return $cells;
	}

	/**
	 * Words a pair of figures as «сайт / маркетплейс»; a figure that is not known is a dash.
	 *
	 * @param string $site   The figure on the site, '' when there is none.
	 * @param string $remote The figure the marketplace has, '' when there is none.
	 * @return string E.g. «500 / 480», «— / 0».
	 */
	private function site_and_remote( string $site, string $remote ): string {
		return $this->figure( $site ) . ' / ' . $this->figure( $remote );
	}

	/**
	 * Words one figure: whole numbers without decimals, '—' for nothing.
	 *
	 * @param string $value Figure as stored.
	 * @return string
	 */
	private function figure( string $value ): string {
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '—';
		}

		$number = (float) $value;

		return (string) ( floor( $number ) === $number ? (int) $number : round( $number, 2 ) );
	}

	/**
	 * Builds the «?» that opens a tooltip (WooCommerce draws it from `data-tip`).
	 *
	 * @param string $tip Text of the tooltip.
	 * @return string Escaped markup.
	 */
	private function help_tip( string $tip ): string {
		return sprintf( '<span class="woocommerce-help-tip" tabindex="0" aria-label="%1$s" data-tip="%1$s"></span>', esc_attr( $tip ) );
	}

	private function renamable_article( array $ids, string $base, string $id ): void {
		$waiting = '' !== $ids['pending'] && '' === $ids['rename_error'];
		$failed  = '' !== $ids['pending'] && '' !== $ids['rename_error'];
		$note    = '';

		if ( $waiting ) {
			$note = sprintf( /* translators: 1: new article, 2: marketplace name, 3: current article. */ __( 'меняется на «%1$s»: %2$s пока знает товар по артикулу «%3$s»', 'woodev-plugin-framework' ), $ids['pending'], $this->names->name(), $ids['offer_id'] );
		} elseif ( $failed && ! empty( $ids['rename_confirmed'] ) ) {
			$note = sprintf( /* translators: %s: article. */ __( 'на маркетплейсе уже «%s», сайт запишет это позже, подробности — в значке «!» у статуса', 'woodev-plugin-framework' ), $ids['pending'] );
		} elseif ( $failed ) {
			$note = sprintf( /* translators: %s: article. */ __( 'не удалось сменить на «%s», подробности — в значке «!» у статуса', 'woodev-plugin-framework' ), $ids['pending'] );
		}

		$this->row(
			$id . 'offer_id',
			__( 'Артикул', 'woodev-plugin-framework' ),
			$this->article_control(
				$id . 'offer_id',
				$base . '[offer_id]',
				$waiting ? $ids['pending'] : $ids['offer_id'],
				'' !== $ids['pending'] ? $ids['pending'] : $ids['offer_id'],
				$ids['offer_id'],
				'' === $note ? '' : ' <span class="woodev-mp-note' . ( $failed ? ' woodev-mp-error' : '' ) . '">(' . esc_html( $note ) . ')</span>'
			),
			sprintf( /* translators: %s: marketplace name. */ __( 'Артикул товара на маркетплейсе. Его можно изменить и после отправки: новый артикул уйдёт на %s отдельным запросом, а до подтверждения товар остаётся под прежним. Если артикул занят или не подходит, останется прежний, а ответ маркетплейса будет в значке «!» у статуса.', 'woodev-plugin-framework' ), $this->names->name() )
		);
	}

	/**
	 * Builds the article: a line with «изменить» that gives way to an input, and beside the input «отменить», which puts the saved article
	 * back and shows the line again (the script does both; the input keeps posting its value while it is hidden).
	 *
	 * @param string $target      Id of the input.
	 * @param string $name        Name of the input.
	 * @param string $shown       The article the line shows.
	 * @param string $value       The value the input starts with: what «отменить» goes back to.
	 * @param string $placeholder The grey text of an empty input.
	 * @param string $note        Escaped markup that follows the line (what the article is waiting for), '' for none.
	 * @return string Escaped markup.
	 */
	private function article_control( string $target, string $name, string $shown, string $value, string $placeholder, string $note = '' ): string {
		return sprintf(
			'<span class="woodev-mp-article"><span class="woodev-mp-article__value">%1$s</span> <button type="button" class="button-link woodev-mp-article__edit" aria-controls="%2$s">%3$s</button>%4$s</span><span class="woodev-mp-article__editor" hidden><input type="text" class="woodev-mp-article__input" id="%2$s" name="%5$s" value="%6$s" placeholder="%7$s" maxlength="50" /> <button type="button" class="button-link woodev-mp-article__cancel" aria-controls="%2$s">%8$s</button></span>',
			esc_html( $shown ),
			esc_attr( $target ),
			esc_html__( 'изменить', 'woodev-plugin-framework' ),
			$note, // Escaped by the caller.
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_html__( 'отменить', 'woodev-plugin-framework' )
		);
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
			// The article is read as a line with «изменить»: most merchants keep the default (the SKU), so the input stays out of the way until asked for.
			// Whether it may change after the card is sent is decided by {@see Link_Settings_Writer::article_is_editable()}; this block only follows it.
			$planned = '' !== $ids['offer_id'] ? $ids['offer_id'] : $ids['auto'];

			$this->row(
				$id . 'offer_id',
				__( 'Артикул', 'woodev-plugin-framework' ),
				$this->article_control( $id . 'offer_id', $base . '[offer_id]', $planned, $ids['offer_id'], $ids['auto'] ),
				$this->renames
					? __( 'Артикул товара на маркетплейсе. По умолчанию берётся артикул (SKU) товара; нажмите «изменить», чтобы задать другой. После отправки артикул тоже можно изменить.', 'woodev-plugin-framework' )
					: __( 'Артикул товара на маркетплейсе. По умолчанию берётся артикул (SKU) товара; нажмите «изменить», чтобы задать другой. После отправки артикул уже не меняется.', 'woodev-plugin-framework' )
			);
		} elseif ( $ids['renamable'] ) {
			$this->renamable_article( $ids, $base, $id );
		} else {
			$this->row(
				$id . 'offer_id',
				__( 'Артикул', 'woodev-plugin-framework' ),
				'<span>' . esc_html( $ids['offer_id'] ) . '</span>' . $this->article_note(
					[
						'pending' => $ids['pending'] ?? '',
						'rename_failed' => ! empty( $ids['rename_error'] ),
					]
				),
				__( 'Артикул уже отправлен на маркетплейс и больше не меняется.', 'woodev-plugin-framework' )
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in article_note().
		}

		if ( '' === $ids['summary'] ) {
			return;
		}

		// The numbers end on the last number: no separator dangles after it. The last number is the link to the card (it is the one the card's
		// public address is built from), so there is no separate «Открыть на…» link.
		$parts = array_values( array_filter( array_map( 'trim', explode( '·', rtrim( trim( $ids['summary'] ), '·' ) ) ), 'strlen' ) );
		$last  = (string) array_pop( $parts );
		$last  = '' === $ids['card_url'] || '' === $last ? esc_html( $last ) : sprintf(
			'<a class="tips" href="%1$s" target="_blank" rel="noopener noreferrer" data-tip="%2$s">%3$s</a>',
			esc_url( $ids['card_url'] ),
			esc_attr( sprintf( /* translators: %s: marketplace name. */ __( 'Открыть на %s', 'woodev-plugin-framework' ), $this->names->name() ) ),
			esc_html( $last )
		);
		$line  = implode( ' · ', array_merge( array_map( 'esc_html', $parts ), '' === $last ? [] : [ $last ] ) );

		$this->row( $id . 'remote_ids', sprintf( /* translators: %s: marketplace name. */ __( 'Номера: %s', 'woodev-plugin-framework' ), $this->names->name() ), '<span>' . $line . '</span>', __( 'Эти номера назначает сам маркетплейс, изменить их нельзя.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Collects everything that is wrong with one product, for the dialog of its state badge: the validator's errors and warnings, the answer the
	 * marketplace last gave, and (while «Логирование» is on) why the product does not sync.
	 *
	 * @param array<string,mixed> $block  Block of an offer.
	 * @param array<string,mixed> $screen Screen (for the links that say where an issue is fixed).
	 * @return array{count:int,html:string} Number of problems and the escaped markup of the dialog.
	 */
	private function problems( array $block, array $screen ): array {
		$validation = $block['validation'];
		$count      = 0;
		$html       = '';

		if ( $validation['failed'] ) {
			++$count;
			$html .= '<p class="woodev-mp-issue woodev-mp-issue--warning">' . esc_html__( 'Проверить товар сейчас не удалось. Откройте «Сопоставление» и убедитесь, что категории загружены.', 'woodev-plugin-framework' ) . $this->fix_link( 'mapping', $screen ) . '</p>';
		}

		$groups = [
			'errors'   => __( 'Исправить перед выгрузкой', 'woodev-plugin-framework' ),
			'warnings' => __( 'Проверьте перед выгрузкой', 'woodev-plugin-framework' ),
		];

		foreach ( $groups as $key => $heading ) {
			if ( [] === $validation[ $key ] ) {
				continue;
			}

			$items = '';

			foreach ( $validation[ $key ] as $issue ) {
				++$count;
				$items .= '<li class="woodev-mp-issue woodev-mp-issue--' . esc_attr( $issue['level'] ) . '">' . esc_html( $issue['message'] ) . $this->fix_link( (string) ( $issue['fix'] ?? '' ), $screen ) . '</li>';
			}

			$html .= '<h3>' . esc_html( $heading ) . '</h3><ul class="woodev-mp-problems__list">' . $items . '</ul>';
		}

		if ( '' !== $block['ids']['rename_error'] ) {
			++$count;
			$html .= '<h3>' . esc_html__( 'Смена артикула', 'woodev-plugin-framework' ) . '</h3><p class="woodev-mp-error">' . ( empty( $block['ids']['rename_confirmed'] )
				? esc_html( sprintf( /* translators: 1: article, 2: marketplace name. */ __( 'Не удалось сменить артикул на «%1$s». Ответ: %2$s', 'woodev-plugin-framework' ), $block['ids']['pending'], $this->names->name() ) ) . '<br />'
				: '' ) . nl2br( esc_html( $block['ids']['rename_error'] ) ) . '</p>';
		}

		if ( '' !== $block['remote_error'] ) {
			++$count;
			$html .= '<h3>' . esc_html( sprintf( /* translators: %s: marketplace name. */ __( 'Ответ: %s', 'woodev-plugin-framework' ), $this->names->name() ) ) . '</h3><p class="woodev-mp-error">' . nl2br( esc_html( $block['remote_error'] ) ) . '</p>';
		}

		// «Почему не синхронизируется» is a diagnostics list: it exists only while «Логирование» is on (a merchant who has not turned it on is not shown it).
		if ( $block['link_id'] > 0 && $block['reasons']['visible'] && [] !== $block['reasons']['list'] ) {
			$items = '';

			foreach ( $block['reasons']['list'] as $reason ) {
				++$count;
				$items .= '<li class="woodev-mp-issue woodev-mp-issue--error">' . esc_html( $reason ) . '</li>';
			}

			$html .= '<h3>' . esc_html__( 'Почему не синхронизируется', 'woodev-plugin-framework' ) . '</h3><ul class="woodev-mp-problems__list">' . $items . '</ul>';
		}

		if ( 0 < $count ) {
			$html .= '<p class="woodev-mp-note woodev-mp-problems__note">' . esc_html__( 'Проверка идёт по данным сайта и не обращается к маркетплейсу. Ошибки нужно исправить до выгрузки.', 'woodev-plugin-framework' ) . '</p>';
		}

		return [
			'count' => $count,
			'html'  => $html,
		];
	}

	private function state_badge( array $badge, ?array $problems ): string {
		if ( null === $problems ) {
			return $this->badge( $badge );
		}

		$label = '<span class="woodev-mp-badge__label">' . esc_html( $badge['label'] ) . '</span>';

		// The mark stands to the right of the words. The tooltip is WooCommerce's (`tips` + `data-tip`), which draws on hover and on focus.
		if ( 0 === $problems['count'] ) {
			return sprintf( '<span class="woodev-mp-badge woodev-mp-badge--%1$s tips" data-tip="%2$s">%3$s<span class="woodev-mp-badge__icon woodev-mp-badge__icon--ok" aria-hidden="true"></span></span>', esc_attr( $badge['tone'] ), esc_attr__( 'Проблем не найдено', 'woodev-plugin-framework' ), $label );
		}

		$hint = sprintf( /* translators: %d: number of problems. */ __( 'Есть проблемы (%d) — нажмите, чтобы посмотреть', 'woodev-plugin-framework' ), $problems['count'] );

		return sprintf(
			'<button type="button" class="woodev-mp-badge woodev-mp-badge--problem woodev-mp-badge--button tips" data-woodev-mp-dialog data-tip="%1$s" aria-haspopup="dialog">%2$s<span class="screen-reader-text">%1$s</span><span class="woodev-mp-badge__icon woodev-mp-badge__icon--problem" aria-hidden="true"></span></button><template data-title="%3$s">%4$s</template>',
			esc_attr( $hint ),
			$label,
			esc_attr__( 'Проверка товара', 'woodev-plugin-framework' ),
			$problems['html']
		);
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
	 * Prints the explicit mapping select, with an icon link to the mappings page beside it (opens in a new window: the product form keeps what
	 * the merchant has typed).
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

		$select = sprintf( '<select id="%1$s" name="%2$s" class="select short woodev-mp-wide">%3$s</select>', esc_attr( $id . 'mapping_id' ), esc_attr( $base . '[mapping_id]' ), $options );
		$link   = '';

		if ( isset( $screen['mappings_url'] ) ) {
			$title = __( 'Перейти на страницу сопоставлений', 'woodev-plugin-framework' );
			$link  = sprintf(
				'<a class="woodev-mp-row-link tips" href="%1$s" target="_blank" rel="noopener noreferrer" data-tip="%2$s" aria-label="%2$s">%3$s</a>',
				esc_url( (string) $screen['mappings_url'] ),
				esc_attr( $title ),
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
			);
		}

		$this->row( $id . 'mapping_id', __( 'Сопоставление', 'woodev-plugin-framework' ), '<span class="woodev-mp-with-action">' . $select . $link . '</span>', __( 'Какой категории маркетплейса и какому набору характеристик соответствует товар. Обычно подходит «По категории»: сопоставление берётся из категории товара на сайте.', 'woodev-plugin-framework' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	private function buttons( array $block, array $screen ): void {
		$confirm = $this->confirmations();
		$group   = $this->button_group(
			$this->button_labels( $block ),
			$this->export_is_new( $block ),
			function ( string $op ) use ( $block, $screen, $confirm ): ?string {
				// The user may not press it: the guard would refuse, so the screen does not offer it.
				return isset( $screen['ops'][ $op ] ) ? $this->op_attributes( $op, $block, $screen ) . ' data-confirm="' . esc_attr( $confirm[ $op ] ?? '' ) . '"' : null;
			}
		);

		$this->print_button_line( $group );
	}

	/**
	 * Prints the buttons of one variation inside its opened panel: the simple product's set, acting on this variation.
	 *
	 * @param array<string,mixed> $block  Block of the variation.
	 * @param array<string,mixed> $screen Screen (`variations`, `parent_id`).
	 * @return void
	 */
	private function variation_buttons( array $block, array $screen ): void {
		if ( ! isset( $screen['variations'] ) ) {
			return;
		}

		$this->print_button_line(
			$this->button_group(
				$this->button_labels( $block ),
				$this->export_is_new( $block ),
				$this->variation_attributes( (int) $block['product_id'], (int) ( $screen['parent_id'] ?? 0 ), (int) $block['account']->id(), $screen['variations'] )
			)
		);
	}

	/**
	 * Prints a group of buttons as one row of the form with the line that shows what the buttons answered.
	 *
	 * @param string $group Escaped markup of {@see self::button_group()}; nothing is printed for ''.
	 * @return void
	 */
	private function print_button_line( string $group ): void {
		if ( '' === $group ) {
			return;
		}

		echo '<p class="form-field woodev-mp-buttons">' . $group . '<span class="woodev-mp-result woodev-mp-note" role="status" aria-live="polite"></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button_group().
	}

	/**
	 * Builds the data attributes a variation's button carries: it posts to the variations' AJAX with the variation's id.
	 *
	 * @param int                 $variation_id Variation.
	 * @param int                 $parent_id    Its variable product.
	 * @param int                 $account_id   Account.
	 * @param array<string,mixed> $facts        The variations' actions (`action`, `nonce`, `buttons`).
	 * @return callable `function ( string $op ): ?string`: the escaped attributes of the button, null for a button not offered.
	 */
	private function variation_attributes( int $variation_id, int $parent_id, int $account_id, array $facts ): callable {
		$confirm = $this->confirmations();

		return function ( string $op ) use ( $variation_id, $parent_id, $account_id, $facts, $confirm ): ?string {
			if ( ! in_array( $op, $facts['buttons'], true ) ) {
				return null;
			}

			return sprintf(
				'data-woodev-mp-vop="%1$s" data-action="%2$s" data-nonce="%3$s" data-product="%4$d" data-variation="%5$d" data-account="%6$d" data-confirm="%7$s"',
				esc_attr( $op ),
				esc_attr( (string) $facts['action'] ),
				esc_attr( (string) $facts['nonce'] ),
				$parent_id,
				$variation_id,
				$account_id,
				esc_attr( $confirm[ $op ] ?? '' )
			);
		};
	}

	/**
	 * Returns the question a destructive button asks before it acts (the second click answers it).
	 *
	 * @return array<string,string> `op` => question.
	 */
	private function confirmations(): array {
		return [
			'archive' => sprintf( /* translators: %s: marketplace name. */ __( 'Точно архивировать на %s? Нажмите ещё раз.', 'woodev-plugin-framework' ), $this->names->name() ),
			'detach'  => __( 'Точно снять связь? Нажмите ещё раз.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Builds a group of buttons: ONE WordPress button group, one row, never two. A button is an icon and its words. Up to {@see self::LABELLED_MAX}
	 * buttons keep their words (the script drops them, with `.is-compact`, when even that few do not fit the width they have); more are icons only. The name is in the
	 * tooltip (WooCommerce's own, drawn from `data-tip`: a browser's `title` does not show in every host of the product screen) and in `aria-label`.
	 *
	 * @param array<string,string> $labels     `op` => label.
	 * @param bool                 $is_new     Whether the export button sends a card for the first time (its icon).
	 * @param callable             $attributes `function ( string $op ): ?string` giving the escaped attributes of a button, null for a button not offered.
	 * @param string               $modifier   Extra class of the group.
	 * @return string Escaped markup, '' when no button is offered.
	 */
	private function button_group( array $labels, bool $is_new, callable $attributes, string $modifier = '' ): string {
		$buttons = [];

		foreach ( $labels as $op => $label ) {
			$attrs = $attributes( (string) $op );

			if ( null === $attrs ) {
				continue;
			}

			$buttons[] = sprintf(
				'<button type="button" class="button woodev-mp-btn tips%1$s" %2$s aria-label="%3$s" data-tip="%3$s">%4$s<span class="woodev-mp-btn__text">%5$s</span></button>',
				'export' === $op ? ' button-primary' : '',
				$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the callback.
				esc_attr( $label ),
				$this->op_icon( (string) $op, $is_new ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
				esc_html( $label )
			);
		}

		if ( [] === $buttons ) {
			return '';
		}

		$count = count( $buttons );

		return sprintf(
			'<span class="%1$s" role="group" aria-label="%2$s">%3$s</span>',
			esc_attr( trim( 'woodev-mp-buttons__row button-group ' . ( $count > self::LABELLED_MAX ? 'woodev-mp-buttons__row--many ' : '' ) . $modifier ) ),
			esc_attr__( 'Действия с карточкой', 'woodev-plugin-framework' ),
			implode( '', $buttons )
		);
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

	private function button_labels( array $block ): array {
		$is_offer = 'offer' === $block['kind'];

		return $this->labels_for(
			$this->export_is_new( $block ),
			$is_offer ? $block['can_archive'] : $block['any_created'],
			$is_offer ? $block['can_detach'] : $block['any_linked'],
			$is_offer || $block['any_unsent']
		);
	}

	/**
	 * Names the buttons of a card by what can be done with it.
	 *
	 * @param bool $is_new      Whether the card has not been sent («Экспортировать» and not «Обновить карточку»).
	 * @param bool $can_archive Whether a created card can be archived.
	 * @param bool $can_detach  Whether the card has a link to take off.
	 * @param bool $can_export  Whether the export button is offered: not for a variable product whose every variation already has its card.
	 * @return array<string,string> `op` => label.
	 */
	private function labels_for( bool $is_new, bool $can_archive, bool $can_detach, bool $can_export = true ): array {
		$labels = [];

		if ( $can_export ) {
			$labels['export'] = $is_new ? __( 'Экспортировать', 'woodev-plugin-framework' ) : __( 'Обновить карточку', 'woodev-plugin-framework' );
		}

		if ( $can_archive ) {
			$labels['archive'] = sprintf( /* translators: %s: marketplace name. */ __( 'Архивировать на %s', 'woodev-plugin-framework' ), $this->names->name() );
		}

		if ( $can_detach ) {
			$labels['detach'] = __( 'Снять связь', 'woodev-plugin-framework' );
		}

		return $labels;
	}

	/**
	 * Returns the explanations of the buttons, shown in «Подсказки».
	 *
	 * @return array<string,string>
	 */
	private function button_tips(): array {
		return [
			'export'  => __( 'Проверит товар и поставит его в очередь на отправку на маркетплейс. Результат появится в состоянии выше через минуту-две.', 'woodev-plugin-framework' ),
			'archive' => __( 'Остаток карточки на маркетплейсе станет нулевым, и карточка уйдёт в архив. Товар на сайте не меняется.', 'woodev-plugin-framework' ),
			'detach'  => __( 'Сайт забудет связь с карточкой маркетплейса. Сама карточка не удаляется и не меняется.', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Prints the «?» in the top-right corner of the block. It opens a dialog with the help: that the buttons work with the saved product, what
	 * each button does, and the category-wide «Связать по артикулу» as a text button with what it works on (a search over the whole category,
	 * not over this one product). A variable product's help starts with how its variants are sent.
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param array<string,mixed> $screen Screen (`ops`, `link_scope`).
	 * @return void
	 */
	private function help( array $block, array $screen ): void {
		$labels = $this->button_labels( $block );
		$hints  = [];

		if ( 'parent' === $block['kind'] ) {
			$hints[] = __( 'Это вариативный товар: на маркетплейс уходит каждый вариант отдельной карточкой. Остатки, цены, склад и проверка товара — у каждого варианта.', 'woodev-plugin-framework' );

			if ( isset( $labels['export'] ) ) {
				$hints[] = __( 'Кнопка «Экспортировать» проверит все варианты сразу и покажет, что мешает.', 'woodev-plugin-framework' );
			}
		}

		$hints[] = isset( $labels['export'] )
			? sprintf( /* translators: %s: name of a button. */ __( 'Кнопки работают с уже сохранённым товаром: если меняли поля выше, сначала сохраните товар («Обновить» справа), затем нажмите «%s».', 'woodev-plugin-framework' ), $labels['export'] )
			: __( 'Кнопки работают с уже сохранённым товаром: если меняли поля выше, сначала сохраните товар («Обновить» справа), затем нажмите нужную кнопку.', 'woodev-plugin-framework' );

		foreach ( $this->button_tips() as $op => $tip ) {
			if ( isset( $labels[ $op ], $screen['ops'][ $op ] ) ) {
				$hints[] = '«' . $labels[ $op ] . '»: ' . $tip;
			}
		}

		$title = __( 'Подсказки', 'woodev-plugin-framework' );

		printf( '<button type="button" class="woodev-mp-help" data-woodev-mp-dialog title="%1$s" aria-label="%1$s" aria-haspopup="dialog">?</button><template data-title="%1$s">', esc_attr( $title ) );

		foreach ( $hints as $hint ) {
			echo '<p class="woodev-mp-note">' . esc_html( $hint ) . '</p>';
		}

		if ( isset( $screen['ops']['link'] ) ) {
			$this->link_by_sku( $block, $screen, (string) ( $screen['link_scope'] ?? '' ) );
		}

		echo '</template>';
	}

	/**
	 * Prints the category-wide «Связать по артикулу»: what it works on, then a text button that runs it (the tab's script handles any element
	 * that carries `data-woodev-mp-op`, also inside the help dialog).
	 *
	 * @param array<string,mixed> $block  Block.
	 * @param array<string,mixed> $screen Screen (`ops`).
	 * @param string              $scope  Name of the product's category, '' when it has none.
	 * @return void
	 */
	private function link_by_sku( array $block, array $screen, string $scope ): void {
		$explanation = '' !== $scope
			? sprintf( /* translators: %s: category name. */ __( 'Ищет карточки маркетплейса с таким же артикулом для всех товаров категории «%s», а не только для этого товара.', 'woodev-plugin-framework' ), $scope )
			: __( 'У товара нет категории, поэтому поиск карточек маркетплейса с таким же артикулом идёт по всем товарам магазина, а не только по этому.', 'woodev-plugin-framework' );
		$label       = '' !== $scope ? __( 'Связать категорию по артикулу', 'woodev-plugin-framework' ) : __( 'Связать все товары по артикулу', 'woodev-plugin-framework' );

		echo '<p class="woodev-mp-note">' . esc_html( $explanation ) . '</p>';
		printf(
			'<p><button type="button" class="button-link woodev-mp-link-op" %1$s>%2$s</button></p>',
			$this->op_attributes( 'link', $block, $screen ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in op_attributes().
			esc_html( $label )
		);
	}

	/**
	 * Prints one `form-field` row, the tooltip inside the label (the stylesheet puts every «?» in one column at the label column's right edge).
	 *
	 * @param string $target  Id of the control the label belongs to, '' for none.
	 * @param string $label   Label text.
	 * @param string $control Escaped markup of the control.
	 * @param string $tip     Tooltip text.
	 * @return void
	 */
	private function row( string $target, string $label, string $control, string $tip ): void {
		$tooltip = '' === $tip ? '' : $this->help_tip( $tip );

		printf(
			'<p class="form-field"><label%1$s>%2$s%3$s</label>%4$s</p>',
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
	 * Builds the «Исправить» link that says where an issue is fixed. It opens in a new window: the product form keeps what the merchant has typed.
	 * An issue fixed on the product itself has no link: the sentence says which field it is.
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

		return ' <a href="' . esc_url( $targets[ $fix ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Исправить', 'woodev-plugin-framework' ) . '</a>';
	}
}
