<?php
/**
 * Unit tests for the markup of the product tab.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
use Woodev\Framework\Marketplace\Admin\Product\Panel_View;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Admin\Shared\Link_Note;
use Woodev\Tests\Unit\TestCase;

final class PanelViewTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'selected' )->alias(
			static function ( $selected, $current = true, $display = true ) {
				return (string) $selected === (string) $current ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'checked' )->justReturn( '' );
	}

	public function test_without_accounts_the_tab_links_to_the_settings(): void {
		$html = $this->render_tab( [], [] );

		$this->assertStringContainsString( 'Fake', $html );
		$this->assertStringContainsString( 'href="https://example.test/settings"', $html );
		$this->assertStringNotContainsString( 'woodev_fake_nonce', $html );
	}

	public function test_the_tab_carries_its_own_nonce_field_and_names_fields_by_account(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'detach' => 'tok-detach' ] );

		$this->assertStringContainsString( 'name="woodev_fake_nonce" value="save-nonce"', $html );
		$this->assertStringContainsString( 'name="woodev_fake[3][sync_stock]"', $html );
	}

	public function test_only_the_buttons_the_user_has_a_nonce_for_are_offered(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'detach' => 'tok-detach' ] );

		$this->assertStringContainsString( 'data-woodev-mp-op="detach"', $html );
		$this->assertStringContainsString( 'data-nonce="tok-detach"', $html );
		$this->assertStringContainsString( 'data-action="woodev_fake_product"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="export"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="archive"', $html );
	}

	public function test_the_oversize_switch_is_shown_only_when_the_marketplace_has_one(): void {
		$without = $this->render_tab( [ $this->block() ], [] );
		$with    = $this->render_tab( [ $this->block() ], [], true );

		$this->assertStringNotContainsString( '[is_oversize]', $without );
		$this->assertStringContainsString( '[is_oversize]', $with );
	}

	public function test_the_price_fields_are_hidden_for_a_marketplace_without_prices(): void {
		$with = $this->render_tab( [ $this->block() ], [] );

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ), false, false ) )->render_tab(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'ops'          => [],
				'link_scope'   => '',
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$without = (string) ob_get_clean();

		$this->assertStringContainsString( '[price][price][kind]', $with );
		$this->assertStringNotContainsString( '[price][price][kind]', $without );
		$this->assertStringContainsString( '[sync_price]', $without );
	}

	public function test_a_variation_panel_uses_the_loop_in_its_field_names(): void {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'loop'         => 4,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="woodev_fake_variation[4][3][sync_price]"', $html );
	}

	public function test_the_badge_collapses_the_lifecycle_into_the_seven_names(): void {
		$this->assertSame( 'Не связан', Card_State_Badge::of( Link_Record::STATE_UNLINKED )['label'] );
		$this->assertSame( 'Создаётся', Card_State_Badge::of( Link_Record::STATE_SKU_CREATED )['label'] );
		$this->assertSame( Card_State_Badge::TONE_OK, Card_State_Badge::of( Link_Record::STATE_CREATED )['tone'] );
		$this->assertSame( Card_State_Badge::TONE_ERROR, Card_State_Badge::of( Link_Record::STATE_IMPORTED_WITH_ERRORS )['tone'] );
		$this->assertTrue( Card_State_Badge::is_unsent( Link_Record::STATE_FAILED ) );
		$this->assertFalse( Card_State_Badge::is_unsent( Link_Record::STATE_CREATED ) );
	}

	public function test_the_mapping_option_is_short_and_its_link_goes_under_the_select(): void {
		$block                       = $this->block();
		$block['mapping']['resolved'] = 'Платья';
		$html                        = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '>По категории: Платья</option>', $html );
		$this->assertMatchesRegularExpression( '#<span class="woodev-mp-with-action"><select [^>]*>.*</select><a class="woodev-mp-row-link tips" href="https://example.test/mappings" target="_blank" rel="noopener noreferrer" data-tip="Перейти на страницу сопоставлений" aria-label="Перейти на страницу сопоставлений"><svg#us', $html );
		$this->assertStringNotContainsString( 'Открыть «Сопоставление»', $html, 'the link is an icon with a tooltip, not a sentence' );
	}

	public function test_the_actions_on_this_product_are_one_group_of_icon_buttons_with_a_short_name(): void {
		$ops  = [
			'export'  => 't1',
			'link'    => 't2',
			'archive' => 't3',
			'detach'  => 't4',
		];
		$html = $this->render_tab( [ $this->block() ], $ops );

		$this->assertSame( 1, substr_count( $html, 'class="woodev-mp-buttons__row button-group" role="group"' ), 'one real WordPress button group; three buttons keep their words (no `--many`)' );
		preg_match( '#<span class="woodev-mp-buttons__row button-group[^"]*".*?</span><span class="woodev-mp-result#us', $html, $row );
		$this->assertSame( 3, substr_count( $row[0], '<button' ), 'export, archive, detach: the category-wide link is not an icon of this product' );
		$this->assertSame( 3, substr_count( $row[0], '<svg class="woodev-mp-btn__icon"' ) );
		$this->assertStringContainsString( 'aria-label="Архивировать на Fake" data-tip="Архивировать на Fake"', $row[0] );
		$this->assertStringContainsString( 'aria-label="Снять связь" data-tip="Снять связь"', $row[0], 'the tooltip is the verb only, not the explanation' );
		$this->assertStringNotContainsString( ' title=', $row[0], 'the tooltip is WooCommerce\'s (tips + data-tip): a native title shows nowhere in some hosts and would double it where it does' );
		$this->assertStringContainsString( 'class="button woodev-mp-btn tips"', $row[0] );
		$this->assertStringContainsString( '<span class="woodev-mp-btn__text">Снять связь</span>', $row[0], 'every button carries its words; the stylesheet hides them when they do not fit' );
		$this->assertStringNotContainsString( 'Связать', $row[0] );
		$this->assertStringNotContainsString( 'Сайт забудет связь', $row[0] );
		$this->assertStringNotContainsString( 'Архивировать:', $html );
		$this->assertStringNotContainsString( '<label></label>', $html );
	}

	public function test_the_help_is_a_question_mark_in_the_corner_that_opens_a_dialog_with_the_category_link_in_it(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'export' => 't1', 'link' => 't2', 'detach' => 't4' ], false, [ 'link_scope' => 'Платья' ] );

		$this->assertMatchesRegularExpression( '#<button type="button" class="woodev-mp-help" data-woodev-mp-dialog title="Подсказки" aria-label="Подсказки" aria-haspopup="dialog">\?</button><template data-title="Подсказки">#u', $html );
		$this->assertStringNotContainsString( '<details', $html, 'no spoiler rows any more' );
		$this->assertStringNotContainsString( '<summary>', $html );
		$this->assertStringContainsString( 'сначала сохраните товар («Обновить» справа), затем нажмите «Обновить карточку».', $html );
		$this->assertStringContainsString( '«Снять связь»: Сайт забудет связь с карточкой маркетплейса.', $html );
		$this->assertStringNotContainsString( '«Архивировать на Fake»:', $html, 'a button the user has no nonce for is not explained' );
		$this->assertStringContainsString( 'для всех товаров категории «Платья», а не только для этого товара.', $html );
		$this->assertMatchesRegularExpression( '#<button type="button" class="button-link woodev-mp-link-op" data-woodev-mp-op="link"[^>]*>Связать категорию по артикулу</button></p></template>#u', $html );
		$this->assertSame( 1, substr_count( $html, '<template' ), 'The help is one dialog, not one per paragraph.' );
	}

	public function test_the_category_link_is_not_offered_without_its_nonce(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'export' => 't1' ], false, [ 'link_scope' => 'Платья' ] );

		$this->assertStringNotContainsString( 'woodev-mp-link-op', $html );
		$this->assertStringNotContainsString( 'Платья', $html );
	}

	public function test_a_product_without_a_category_links_the_whole_shop(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'link' => 't2' ] );

		$this->assertStringContainsString( '>Связать все товары по артикулу</button>', $html );
		$this->assertStringContainsString( 'идёт по всем товарам магазина', $html );
	}

	public function test_the_logging_hint_is_never_shown_to_a_merchant_who_has_not_turned_logging_on(): void {
		$block            = $this->block();
		$block['link_id'] = 7;
		$html             = $this->render_tab( [ $block ], [] );

		$this->assertStringNotContainsString( 'Включите «Логирование»', $html );
		$this->assertStringNotContainsString( 'Почему не синхронизируется', $html );
	}

	public function test_a_product_without_problems_has_its_badge_with_a_check_and_no_check_row(): void {
		$html = $this->render_tab( [ $this->block() ], [] );

		$this->assertMatchesRegularExpression( '#<h3 class="woodev-mp-block__title">Fake <span class="woodev-mp-badge woodev-mp-badge--ok tips" data-tip="Проблем не найдено"><span class="woodev-mp-badge__label">Создан</span><span class="woodev-mp-badge__icon woodev-mp-badge__icon--ok" aria-hidden="true"></span></span></h3>#u', $html );
		$this->assertStringNotContainsString( 'woodev-mp-badge--button', $html, 'nothing to open: the badge is not a button' );
		$this->assertStringNotContainsString( '>Проверка<', $html, 'the separate «Проверка» row is gone' );
		$this->assertStringNotContainsString( 'Товар готов к выгрузке', $html );
	}

	public function test_a_product_with_problems_has_an_orange_badge_that_opens_a_dialog_listing_every_problem_with_working_links(): void {
		$block                         = $this->block();
		$block['remote_error']         = "Ответ первой строки\nвторая строка";
		$block['validation']['errors'] = [
			[
				'level'   => 'error',
				'message' => 'Не указан вес',
				'fix'     => 'mapping',
			],
			[
				'level'   => 'error',
				'message' => 'Нет главного фото',
				'fix'     => 'product',
			],
		];
		$block['validation']['warnings'] = [
			[
				'level'   => 'warning',
				'message' => 'Короткое описание',
				'fix'     => 'account',
			],
		];
		$html                          = $this->render_tab( [ $block ], [] );

		$this->assertMatchesRegularExpression( '#<button type="button" class="woodev-mp-badge woodev-mp-badge--problem woodev-mp-badge--button tips" data-woodev-mp-dialog data-tip="Есть проблемы \(4\) — нажмите, чтобы посмотреть" aria-haspopup="dialog"><span class="woodev-mp-badge__label">Создан</span><span class="screen-reader-text">Есть проблемы \(4\) — нажмите, чтобы посмотреть</span><span class="woodev-mp-badge__icon woodev-mp-badge__icon--problem" aria-hidden="true"></span></button><template data-title="Проверка товара">#u', $html );
		$this->assertStringContainsString( '<h3>Исправить перед выгрузкой</h3><ul class="woodev-mp-problems__list">', $html );
		$this->assertStringContainsString( '<li class="woodev-mp-issue woodev-mp-issue--error">Не указан вес <a href="https://example.test/mappings" target="_blank" rel="noopener noreferrer">Исправить</a></li>', $html );
		$this->assertStringContainsString( '<li class="woodev-mp-issue woodev-mp-issue--error">Нет главного фото</li>', $html, 'a problem fixed on the product itself has no link: the sentence names the field' );
		$this->assertStringContainsString( '<h3>Проверьте перед выгрузкой</h3>', $html );
		$this->assertStringContainsString( 'Короткое описание <a href="https://example.test/settings" target="_blank" rel="noopener noreferrer">Исправить</a>', $html );
		$this->assertStringContainsString( '<h3>Ответ: Fake</h3><p class="woodev-mp-error">Ответ первой строки<br />', $html );
		$this->assertStringNotContainsString( '<details', $html );
		$this->assertStringNotContainsString( '>Проверка<', $html, 'the separate «Проверка» row is gone' );
	}

	public function test_a_check_that_could_not_run_counts_as_a_problem_with_a_link_to_the_mappings(): void {
		$block                         = $this->block();
		$block['validation']['failed'] = true;
		$html                          = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( 'woodev-mp-badge--problem', $html );
		$this->assertStringContainsString( 'Проверить товар сейчас не удалось.', $html );
		$this->assertStringContainsString( 'data-tip="Есть проблемы (1) — нажмите, чтобы посмотреть"', $html );
	}

	public function test_the_sync_reasons_are_in_the_dialog_only_while_logging_shows_them(): void {
		$block            = $this->block();
		$block['link_id'] = 7;
		$block['reasons'] = [
			'visible' => true,
			'list'    => [ 'Лицензия не действует' ],
		];
		$html             = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<h3>Почему не синхронизируется</h3>', $html );
		$this->assertStringContainsString( 'Лицензия не действует', $html );
		$this->assertStringContainsString( 'data-tip="Есть проблемы (1) — нажмите, чтобы посмотреть"', $html );

		$block['reasons']['visible'] = false;
		$html                        = $this->render_tab( [ $block ], [] );

		$this->assertStringNotContainsString( 'Лицензия не действует', $html );
		$this->assertStringContainsString( 'woodev-mp-badge--ok', $html );
	}

	public function test_a_price_that_did_not_take_effect_is_a_problem_whether_or_not_logging_is_on(): void {
		$block                  = $this->block();
		$block['link_id']       = 7;
		$block['price_issue'] = 'Fake держит новую цену в карантине: она намного ниже текущей';
		$block['reasons']       = [
			'visible' => false,
			'list'    => [],
		];
		$html                   = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<h3>Новая цена</h3><p class="woodev-mp-error">Fake держит новую цену в карантине: она намного ниже текущей</p>', $html );
		$this->assertStringContainsString( 'data-tip="Есть проблемы (1) — нажмите, чтобы посмотреть"', $html );
	}

	public function test_the_variants_table_says_at_the_price_why_it_did_not_take_effect(): void {
		$parent         = $this->block();
		$parent['kind'] = 'parent';
		$parent        += [
			'counts'      => [],
			'any_created' => true,
			'any_linked'  => true,
			'any_unsent'  => false,
		];
		$parent['rows'] = [
			[
				'id'          => 5,
				'name'        => 'S',
				'badge'       => Card_State_Badge::of( Link_Record::STATE_CREATED ),
				'offer_id'    => 'ART-S',
				'planned'     => false,
				'stock'       => '',
				'price'       => '100',
				'currency'    => 'RUB',
				'price_issue' => 'Цена не отправлена <img src=x onerror=alert(1)>',
			],
		];
		$html           = $this->render_tab( [ $parent ], [] );

		$this->assertMatchesRegularExpression( '#<td class="woodev-mp-variations__price"[^>]*>— / 100<span class="woocommerce-help-tip"#u', $html );
		$this->assertStringContainsString( 'data-tip="Цена не отправлена &amp;lt;img', $html, 'WooCommerce draws the tooltip as HTML: the text is encoded twice' );
	}

	public function test_one_account_shows_no_account_name_and_no_select(): void {
		$html = $this->render_tab( [ $this->block() ], [] );

		$this->assertStringNotContainsString( 'Основной', $html );
		$this->assertStringNotContainsString( 'woodev-mp-account-switch', $html );
		$this->assertStringNotContainsString( 'Аккаунт Fake', $html );
		$this->assertStringContainsString( '<h3 class="woodev-mp-block__title">Fake ', $html );
	}

	public function test_several_accounts_get_one_select_and_show_one_block_at_a_time(): void {
		$second            = $this->block();
		$second['account'] = new Account( 5, 'fake', 'Магазин два', true );
		$html              = $this->render_tab( [ $this->block(), $second ], [] );

		$this->assertSame( 1, substr_count( $html, 'class="woodev-mp-account-switch"' ) );
		$this->assertStringContainsString( '<label for="woodev_fake_account">Аккаунт Fake:</label>', $html );
		$this->assertStringContainsString( '<option value="3" selected="selected">Основной</option><option value="5">Магазин два</option>', $html );
		$this->assertStringContainsString( 'Аккаунт Fake: Основной', $html );
		$this->assertMatchesRegularExpression( '#data-account="3"><h3#', $html, 'the first account is shown at first' );
		$this->assertMatchesRegularExpression( '#data-account="5" hidden><h3#', $html, 'the others stay in the form (so they are saved) but are hidden' );
		$this->assertStringContainsString( 'name="woodev_fake[5][sync_stock]"', $html );
	}

	public function test_the_article_is_a_line_with_a_change_link_until_the_card_is_sent(): void {
		$block                   = $this->block();
		$block['ids']['editable'] = true;
		$block['ids']['offer_id'] = '';
		$block['ids']['auto']     = 'SKU-77';
		$html                    = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span class="woodev-mp-article"><span class="woodev-mp-article__value">SKU-77</span> <button type="button" class="button-link woodev-mp-article__edit" aria-controls="woodev_fake_a3_offer_id">изменить</button></span>', $html );
		$this->assertStringContainsString( '<span class="woodev-mp-article__editor" hidden><input type="text" class="woodev-mp-article__input" id="woodev_fake_a3_offer_id" name="woodev_fake[3][offer_id]" value="" placeholder="SKU-77" maxlength="50" /> <button type="button" class="button-link woodev-mp-article__cancel" aria-controls="woodev_fake_a3_offer_id">отменить</button></span>', $html, 'the input comes with a clickable «отменить»' );

		$block['ids']['offer_id'] = 'CHOSEN-1';
		$html                     = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span class="woodev-mp-article__value">CHOSEN-1</span>', $html, 'the chosen article, not the SKU, is the line' );
		$this->assertStringContainsString( 'value="CHOSEN-1"', $html );
	}

	public function test_a_sent_article_is_text_without_a_change_link(): void {
		$html = $this->render_tab( [ $this->block() ], [] );

		$this->assertStringContainsString( '<span>ART-1</span>', $html );
		$this->assertStringNotContainsString( 'woodev-mp-article__edit', $html );
		$this->assertStringNotContainsString( 'name="woodev_fake[3][offer_id]"', $html );
		$this->assertStringContainsString( 'Артикул уже отправлен на маркетплейс и больше не меняется.', $html );
	}

	public function test_a_sent_article_is_a_line_with_a_change_link_when_the_adapter_can_change_it(): void {
		$block                     = $this->block();
		$block['ids']['renamable'] = true;
		$html                      = $this->render_tab( [ $block ], [], false, [], true );

		$this->assertStringContainsString( '<span class="woodev-mp-article"><span class="woodev-mp-article__value">ART-1</span> <button type="button" class="button-link woodev-mp-article__edit" aria-controls="woodev_fake_a3_offer_id">изменить</button></span>', $html );
		$this->assertStringContainsString( 'name="woodev_fake[3][offer_id]" value="ART-1" placeholder="ART-1" maxlength="50" />', $html, 'the input starts on the article the card has, which «отменить» goes back to' );
		$this->assertStringContainsString( 'woodev-mp-article__cancel', $html );
		$this->assertStringNotContainsString( 'больше не меняется', $html );
		$this->assertStringContainsString( 'После отправки артикул тоже можно изменить', $this->render_tab( [ $this->editable_block() ], [], false, [], true ) );
		$this->assertStringContainsString( 'После отправки артикул уже не меняется', $this->render_tab( [ $this->editable_block() ], [] ), 'an adapter that cannot change it keeps saying so' );
	}

	public function test_a_waiting_change_shows_the_wanted_article_and_what_the_marketplace_still_knows(): void {
		$block                     = $this->block();
		$block['ids']['renamable'] = true;
		$block['ids']['pending']   = 'ART-NEW';
		$html                      = $this->render_tab( [ $block ], [], false, [], true );

		$this->assertStringContainsString( '<span class="woodev-mp-article__value">ART-NEW</span>', $html );
		$this->assertStringContainsString( '(меняется на «ART-NEW»: Fake пока знает товар по артикулу «ART-1»)', $html );
		$this->assertStringContainsString( 'value="ART-NEW"', $html );
		$this->assertStringNotContainsString( 'Смена артикула', $html, 'a change that is only waiting is not a problem' );
	}

	public function test_a_refused_change_keeps_the_old_article_and_puts_the_answer_in_the_problems_of_the_badge(): void {
		$block                         = $this->block();
		$block['ids']['renamable']     = true;
		$block['ids']['pending']       = 'TAKEN';
		$block['ids']['rename_error']  = 'OFFER_ID_ALREADY_EXISTS';
		$html                          = $this->render_tab( [ $block ], [], false, [], true );

		$this->assertStringContainsString( '<span class="woodev-mp-article__value">ART-1</span>', $html );
		$this->assertStringContainsString( '(не удалось сменить на «TAKEN», подробности — в значке «!» у статуса)', $html );
		$this->assertStringContainsString( 'woodev-mp-badge--problem', $html );
		$this->assertStringContainsString( '<h3>Смена артикула</h3>', $html );
		$this->assertStringContainsString( 'Не удалось сменить артикул на «TAKEN». Ответ: Fake<br />OFFER_ID_ALREADY_EXISTS', $html );
		$this->assertStringContainsString( 'value="TAKEN"', $html, 'saving again asks for the same article again' );
	}

	public function test_a_waiting_change_is_noted_on_the_frozen_article_while_its_card_is_exported(): void {
		$block                   = $this->block();
		$block['ids']['pending'] = 'ART-NEW';
		$html                    = $this->render_tab( [ $block ], [], false, [], true );

		$this->assertStringContainsString( '<span>ART-1</span> <span class="woodev-mp-note">(меняется на «ART-NEW»)</span>', $html );
		$this->assertStringNotContainsString( 'woodev-mp-article__edit', $html );
	}

	public function test_a_change_the_marketplace_made_but_the_shop_could_not_write_says_so_in_the_line_and_the_dialog(): void {
		$block                             = $this->block();
		$block['ids']['renamable']         = true;
		$block['ids']['pending']           = 'DEST';
		$block['ids']['rename_error']      = 'Маркетплейс уже сменил артикул на «DEST», но сайт не смог это записать.';
		$block['ids']['rename_confirmed']  = true;
		$html                              = $this->render_tab( [ $block ], [], false, [], true );

		$this->assertStringContainsString( '(на маркетплейсе уже «DEST», сайт запишет это позже', $html );
		$this->assertStringContainsString( '<h3>Смена артикула</h3><p class="woodev-mp-error">Маркетплейс уже сменил артикул', $html );
		$this->assertStringNotContainsString( 'Не удалось сменить артикул на', $html );
	}

	public function test_the_variants_table_says_which_articles_are_changing(): void {
		$block                = $this->block();
		$block['kind']        = 'parent';
		$block['counts']      = [];
		$block['any_created'] = true;
		$block['any_linked']  = true;
		$block['any_unsent']  = false;
		$row                  = [
			'id'       => 5,
			'name'     => 'S',
			'badge'    => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'offer_id' => 'ART-S',
			'planned'  => false,
			'stock'    => '',
			'price'    => '',
			'currency' => 'RUB',
		];
		$block['rows']        = [
			array_merge( $row, [ 'pending' => 'ART-S2', 'rename_failed' => false ] ),
			array_merge( $row, [ 'id' => 6, 'offer_id' => 'ART-M', 'pending' => 'ART-M2', 'rename_failed' => true ] ),
		];
		$html                 = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( 'ART-S <span class="woodev-mp-note">(меняется на «ART-S2»)</span>', $html );
		$this->assertStringContainsString( 'ART-M <span class="woodev-mp-note woodev-mp-error">(не удалось сменить на «ART-M2»)</span>', $html );
	}

	public function test_a_variation_has_a_badge_and_no_check_row_and_the_article_line(): void {
		$block                         = $this->block();
		$block['validation']['errors'] = [
			[
				'level'   => 'error',
				'message' => 'Не указан вес',
				'fix'     => 'mapping',
			],
		];
		$block['ids']['editable']      = true;
		$block['ids']['offer_id']      = '';
		$block['ids']['auto']          = 'VAR-1';

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $block ],
				'nonce'        => 'save-nonce',
				'loop'         => 2,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<h4>Fake: <button type="button" class="woodev-mp-badge woodev-mp-badge--problem#u', $html );
		$this->assertStringContainsString( 'Не указан вес', $html );
		$this->assertStringNotContainsString( '>Проверка<', $html );
		$this->assertStringContainsString( 'woodev-mp-article__edit', $html );
	}

	public function test_a_variations_collapsed_header_shows_the_same_badge_as_the_opened_variation(): void {
		$clean = $this->block();

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation_header(
			[
				'blocks'       => [ $clean ],
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$one = (string) ob_get_clean();

		$this->assertStringStartsWith( '<span class="woodev-mp-header-state"><span class="woodev-mp-header-state__name">Fake</span><span class="woodev-mp-badge woodev-mp-badge--ok tips" data-tip="Проблем не найдено">', $one );

		$sick                         = $this->block();
		$sick['state']                = Link_Record::STATE_UNLINKED;
		$sick['badge']                = Card_State_Badge::of( Link_Record::STATE_UNLINKED );
		$sick['validation']['errors'] = [
			[
				'level'   => 'error',
				'message' => 'Не указан вес',
				'fix'     => 'mapping',
			],
		];
		$second            = $sick;
		$second['account'] = new Account( 5, 'fake', 'Два', true );

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation_header(
			[
				'blocks'       => [ $clean, $second ],
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$two = (string) ob_get_clean();

		$this->assertStringContainsString( 'Fake · Основной', $two );
		$this->assertStringContainsString( 'Fake · Два', $two );
		$this->assertMatchesRegularExpression( '#Fake · Два</span><button type="button" class="woodev-mp-badge woodev-mp-badge--problem woodev-mp-badge--button tips" data-woodev-mp-dialog#u', $two, 'a problem shows in the header, orange, with the dialog' );
		$this->assertStringContainsString( 'Не указан вес <a href="https://example.test/mappings"', $two );
		$this->assertStringContainsString( '<span class="woodev-mp-badge__icon woodev-mp-badge__icon--problem" aria-hidden="true"></span></button><template', $two, 'the warning mark is to the right of the words' );
	}

	/**
	 * Renders the collapsed header and the opened panel of a variation of a product with two accounts.
	 *
	 * @param array<string,mixed> $extra Keys to add to the screen (`default_account`).
	 * @return array{header:string,panel:string}
	 */
	private function render_variation_of_two_accounts( array $extra = [] ): array {
		$second            = $this->block();
		$second['account'] = new Account( 5, 'fake', 'Два', true );
		$screen            = $extra + [
			'blocks'       => [ $this->block(), $second ],
			'nonce'        => 'save-nonce',
			'loop'         => 0,
			'settings_url' => 'https://example.test/settings',
			'mappings_url' => 'https://example.test/mappings',
		];
		$view              = new Panel_View( new Screen_Names( 'fake', 'Fake' ) );

		ob_start();
		$view->render_variation_header( $screen );
		$header = (string) ob_get_clean();

		ob_start();
		$view->render_variation( $screen );
		$panel = (string) ob_get_clean();

		return [
			'header' => $header,
			'panel'  => $panel,
		];
	}

	public function test_with_several_accounts_a_variation_shows_one_account_and_the_select_picks_it(): void {
		$html = $this->render_variation_of_two_accounts();

		$this->assertSame( 1, substr_count( $html['header'], '<span class="woodev-mp-header-state" data-marketplace="fake" data-account="3">' ), 'the first account is shown in the header at first' );
		$this->assertSame( 1, substr_count( $html['header'], '<span class="woodev-mp-header-state" data-marketplace="fake" data-account="5" hidden>' ), 'the other account has its slot, hidden' );
		$this->assertStringContainsString( ' woodev-mp-block--variation" data-marketplace="fake" data-account="3">', $html['panel'] );
		$this->assertStringContainsString( ' woodev-mp-block--variation" data-marketplace="fake" data-account="5" hidden>', $html['panel'], 'the other account stays in the form, so it is saved, but is hidden' );
	}

	public function test_a_variation_starts_on_the_default_account_of_the_screen(): void {
		$html = $this->render_variation_of_two_accounts( [ 'default_account' => 5 ] );

		$this->assertStringContainsString( 'data-account="3" hidden><span class="woodev-mp-header-state__name">', $html['header'] );
		$this->assertStringContainsString( 'data-account="5"><span class="woodev-mp-header-state__name">', $html['header'] );
		$this->assertStringContainsString( 'data-account="3" hidden>', $html['panel'] );
		$this->assertStringContainsString( 'data-account="5">', $html['panel'] );
	}

	public function test_the_account_select_names_its_marketplace_so_the_variations_of_another_one_are_left_alone(): void {
		$second            = $this->block();
		$second['account'] = new Account( 5, 'fake', 'Магазин два', true );

		$this->assertStringContainsString( 'class="woodev-mp-account-switch" data-remember="woodev_fake_account" data-marketplace="fake"', $this->render_tab( [ $this->block(), $second ], [] ) );
	}

	public function test_with_one_account_a_variation_has_nothing_to_hide(): void {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation_header(
			[
				'blocks'       => [ $this->block() ],
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-account', $html );
		$this->assertStringNotContainsString( ' hidden>', $html );
	}

	public function test_the_default_account_is_the_one_the_screen_names_and_the_variants_headings_are_short(): void {
		$second            = $this->block();
		$second['account'] = new Account( 5, 'fake', 'Магазин два', true );
		$html              = $this->render_tab( [ $this->block(), $second ], [], false, [ 'default_account' => 5 ] );

		$this->assertStringContainsString( '<option value="3">Основной</option><option value="5" selected="selected">Магазин два</option>', $html );
		$this->assertMatchesRegularExpression( '#data-account="3" hidden><h3#', $html );
		$this->assertMatchesRegularExpression( '#data-account="5"><h3#', $html );
	}

	public function test_the_variants_table_has_short_headings(): void {
		$block                = $this->block();
		$block['kind']        = 'parent';
		$block['counts']      = [];
		$block['any_created'] = false;
		$block['any_linked']  = false;
		$block['any_unsent']  = true;
		$block['rows']        = [
			[
				'id'       => 5,
				'name'     => 'S',
				'badge'    => Card_State_Badge::of( Link_Record::STATE_UNLINKED ),
				'offer_id' => 'ART-S',
				'planned'  => false,
				'stock'    => '',
				'price'    => '',
				'currency' => 'RUB',
			],
		];
		$html                 = $this->render_tab( [ $block ], [] );

		$this->assertMatchesRegularExpression( '#<th scope="col">Остаток<span class="woocommerce-help-tip"[^>]*></span></th><th scope="col">Цена<span class="woocommerce-help-tip"[^>]*></span></th>#u', $html );
		$this->assertStringContainsString( 'data-tip="Слева — остаток на сайте, справа — на Fake. Прочерк — данных пока нет."', $html, 'the «?» explains «сайт / Fake»' );
		$this->assertStringNotContainsString( 'Цена: Fake', $html );
	}

	public function test_a_note_that_asks_for_something_is_a_line_of_the_block_and_a_problem_of_its_badge(): void {
		$block         = $this->block();
		$block['note'] = new Link_Note( "Добавьте документы <b>в кабинете</b>.\nИначе карточку заблокируют." );
		$html          = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<p class="form-field woodev-mp-intro"><span class="woodev-mp-issue woodev-mp-issue--warning">Добавьте документы &lt;b&gt;в кабинете&lt;/b&gt;.<br />', $html );
		$this->assertStringContainsString( 'data-tip="Есть проблемы (1) — нажмите, чтобы посмотреть"', $html );
		$this->assertStringContainsString( '<h3>Замечание: Fake</h3><p class="woodev-mp-issue woodev-mp-issue--warning">Добавьте документы', $html );
		$this->assertStringNotContainsString( '<b>в кабинете</b>', $html );
	}

	public function test_a_note_that_asks_for_nothing_is_only_a_line(): void {
		$block         = $this->block();
		$block['note'] = new Link_Note( 'Карточка проверяется.', Link_Note::TONE_INFO );
		$html          = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<p class="form-field woodev-mp-intro"><span class="woodev-mp-note">Карточка проверяется.</span></p>', $html );
		$this->assertStringContainsString( 'data-tip="Проблем не найдено"', $html );
		$this->assertStringNotContainsString( 'Замечание', $html );
	}

	public function test_a_variation_panel_shows_its_note_under_the_badge(): void {
		$block         = $this->block();
		$block['note'] = new Link_Note( 'Отклонена модерацией.', Link_Note::TONE_ERROR );

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $block ],
				'nonce'        => 'save-nonce',
				'loop'         => 0,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#</template></h4><p class="form-field woodev-mp-intro"><span class="woodev-mp-issue woodev-mp-issue--error">Отклонена модерацией\.</span></p>#u', $html );
	}

	public function test_a_variant_with_a_note_has_it_as_the_tooltip_of_its_badge_and_the_badge_changes_colour(): void {
		$block                = $this->block();
		$block['kind']        = 'parent';
		$block['counts']      = [];
		$block['any_created'] = true;
		$block['any_linked']  = true;
		$block['any_unsent']  = false;
		$row                  = [
			'id'       => 5,
			'name'     => 'S',
			'badge'    => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'offer_id' => 'ART-S',
			'planned'  => false,
			'stock'    => '',
			'price'    => '',
			'currency' => 'RUB',
		];
		$block['rows']        = [
			array_merge( $row, [ 'note' => new Link_Note( 'Ошибка <img src=x onerror=alert(1)> от «маркетплейса»' ) ] ),
			array_merge( $row, [ 'id' => 6, 'note' => new Link_Note( 'Отклонена', Link_Note::TONE_ERROR ) ] ),
			array_merge( $row, [ 'id' => 7, 'note' => new Link_Note( 'Проверяется', Link_Note::TONE_INFO ) ] ),
			array_merge( $row, [ 'id' => 8, 'note' => null ] ),
		];
		$html                 = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span class="woodev-mp-badge woodev-mp-badge--problem tips" tabindex="0" data-tip="Ошибка &amp;lt;img src=x onerror=alert(1)&amp;gt; от «маркетплейса»"', $html, 'WooCommerce draws the tooltip as HTML: the text is encoded twice' );
		$this->assertStringContainsString( '<span class="woodev-mp-badge woodev-mp-badge--error tips" tabindex="0" data-tip="Отклонена" aria-label="Создан. Отклонена">Создан</span>', $html );
		$this->assertStringContainsString( '<span class="woodev-mp-badge woodev-mp-badge--ok tips" tabindex="0" data-tip="Проверяется"', $html );
		$this->assertStringContainsString( '<td class="woodev-mp-variations__state"><span class="woodev-mp-badge woodev-mp-badge--ok">Создан</span></td>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	public function test_a_variable_product_has_an_intro_across_the_tab_and_variants_whose_notes_stay_on_their_line(): void {
		$block         = $this->block();
		$block['kind'] = 'parent';
		$block['counts']      = [ 'Не связан' => 1 ];
		$block['any_created'] = false;
		$block['any_linked']  = false;
		$block['any_unsent']  = true;
		$block['rows']        = [
			[
				'id'       => 5,
				'name'     => 'S',
				'badge'    => Card_State_Badge::of( Link_Record::STATE_UNLINKED ),
				'offer_id' => 'ART-S',
				'planned'  => true,
				'stock'    => '',
				'price'    => '',
				'currency' => 'RUB',
			],
		];
		$html = $this->render_tab( [ $block ], [ 'export' => 't1' ] );

		$this->assertStringContainsString( '<p class="form-field woodev-mp-intro"><span class="woodev-mp-note">Остатки, цены и проверка — у каждого варианта, во вкладке «Вариации».</span></p>', $html );
		$this->assertStringContainsString( 'Это вариативный товар: на маркетплейс уходит каждый вариант отдельной карточкой.', $html );
		$this->assertMatchesRegularExpression( '#<template data-title="Подсказки"><p class="woodev-mp-note">Это вариативный товар#u', $html, 'the rest of the explanation is in the help dialog' );
		$this->assertStringContainsString( '<div class="woodev-mp-variations-wrap"><table class="woodev-mp-variations">', $html );
		$this->assertMatchesRegularExpression( '#<td class="woodev-mp-variations__article" data-label="Артикул">ART-S<span class="woocommerce-help-tip"[^>]*data-tip="Артикул будет отправлен на маркетплейс вместе с карточкой\."></span></td>#u', $html, '«(будет отправлен)» is in the tooltip, not in the cell' );
		$this->assertStringNotContainsString( '(будет отправлен)', $html );
		$this->assertStringContainsString( '</table></div>', $html );
		$this->assertStringContainsString( 'class="woodev-mp-help"', $html );
	}

	public function test_the_last_number_is_the_link_to_the_card_and_no_number_dangles(): void {
		$block                     = $this->block();
		$block['ids']['summary']   = 'ID 1 · SKU 2 · модель 3 · ';
		$block['ids']['card_url']  = 'https://example.test/card/1';
		$html                      = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span>ID 1 · SKU 2 · <a class="tips" href="https://example.test/card/1" target="_blank" rel="noopener noreferrer" data-tip="Открыть на Fake">модель 3</a></span>', $html );
		$this->assertStringNotContainsString( '· </span>', $html );
		$this->assertStringNotContainsString( '>Открыть на Fake</a>', $html, 'there is no separate «Открыть на…» link' );

		$block['ids']['card_url'] = '';
		$html                     = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span>ID 1 · SKU 2 · модель 3</span>', $html, 'no link without an address, and no separator dangles after the last number' );
	}

	public function test_a_tooltip_sits_inside_its_label(): void {
		$block                     = $this->block();
		$block['ids']['summary']   = 'ID 1';
		$html                      = $this->render_tab( [ $block ], [] );

		$this->assertMatchesRegularExpression( '#<label for="[^"]*remote_ids">Номера: Fake<span class="woocommerce-help-tip"[^>]*></span></label>#u', $html );
	}

	public function test_the_price_boxes_show_the_computed_price_with_the_shops_currency_formatting(): void {
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, $args = [] ): string {
				return '<span class="amount"><bdi>' . number_format( (float) $amount, 2, ',', '' ) . '&nbsp;<span class="symbol">&#8381;</span></bdi></span>';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$block                      = $this->block();
		$block['price']['computed'] = [
			'price'     => '99.00',
			'old_price' => '120.5',
			'min_price' => '',
		];
		$html                       = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( 'placeholder="99,00 ₽"', $html );
		$this->assertStringContainsString( 'placeholder="120,50 ₽"', $html );
		$this->assertStringContainsString( 'placeholder="Не задана"', $html );
	}

	public function test_more_than_three_buttons_are_icons_only_and_up_to_three_keep_their_words(): void {
		$view   = new Panel_View( new Screen_Names( 'fake', 'Fake' ) );
		$group  = new \ReflectionMethod( $view, 'button_group' );
		$all    = static function ( string $op ): string {
			return 'data-x="' . $op . '"';
		};
		$labels = [
			'a' => 'А',
			'b' => 'Б',
			'c' => 'В',
			'd' => 'Г',
		];

		if ( PHP_VERSION_ID < 80100 ) {
			$group->setAccessible( true ); // Needed before PHP 8.1, deprecated from 8.5.
		}

		$four  = (string) $group->invoke( $view, $labels, false, $all );
		$three = (string) $group->invoke( $view, array_slice( $labels, 0, 3 ), false, $all );
		$none  = (string) $group->invoke( $view, $labels, false, static function (): ?string {
			return null;
		} );

		$this->assertStringContainsString( 'button-group woodev-mp-buttons__row--many"', $four, 'four buttons: the PHP adds the class that hides the words' );
		$this->assertStringNotContainsString( 'woodev-mp-buttons__row--many', $three, 'up to three keep their words' );
		$this->assertSame( '', $none, 'nothing offered: no empty group' );
	}

	public function test_the_variants_table_is_a_records_table_with_ticks_pictures_site_and_marketplace_figures_and_the_buttons_of_each_row(): void {
		$html = $this->render_tab( [ $this->parent_block() ], [ 'export' => 't1' ], false, [ 'variations' => $this->variation_facts() ] );

		$this->assertStringContainsString( '<div class="woodev-mp-variants" data-account="3" data-product="41" data-action="woodev_fake_variations" data-nonce="var-nonce">', $html );
		$this->assertStringContainsString( '<select id="woodev_fake_a3_bulk" class="woodev-mp-variants__select"><option value="">Выберите действие</option><option value="export">Экспортировать / Обновить карточку</option><option value="sync_stock_on">Включить синхронизацию остатков</option></select> <button type="button" class="button woodev-mp-variants__apply">Применить</button>', $html );
		$this->assertStringContainsString( '<td class="woodev-mp-variations__check"><input type="checkbox" class="woodev-mp-variants__all"', $html, 'the first column is the ticks, the heading ticks all' );
		$this->assertMatchesRegularExpression( '#<td class="woodev-mp-variations__check"><input type="checkbox" class="woodev-mp-variants__check" value="5" aria-label="Отметить вариант «S»" /></td><td class="woodev-mp-variations__image"><img src="https://example.test/s\.png"#u', $html );
		$this->assertStringContainsString( '<td class="woodev-mp-variations__stock" data-label="Остаток">500 / 480</td><td class="woodev-mp-variations__price" data-label="Цена">1990 / —</td>', $html, 'stock and price are «сайт / Fake»' );
		$this->assertStringContainsString( '<td class="woodev-mp-variations__stock" data-label="Остаток">— / 0</td><td class="woodev-mp-variations__price" data-label="Цена">99.5 / 99.5</td>', $html );
		$this->assertStringContainsString( '<th scope="col" class="woodev-mp-variations__actions">Действия</th>', $html );
		$this->assertStringContainsString( 'data-woodev-mp-vop="export" data-action="woodev_fake_variations" data-nonce="var-nonce" data-product="41" data-variation="5" data-account="3" data-confirm=""', $html );
		$this->assertMatchesRegularExpression( '#<td class="woodev-mp-variations__actions"><span class="woodev-mp-buttons__row button-group woodev-mp-buttons__row--icons"#u', $html, 'a row of the table keeps to icons' );
		$this->assertSame( 3, substr_count( $html, 'data-woodev-mp-vop="detach"' ) + substr_count( $html, 'data-woodev-mp-vop="archive"' ) + 1, 'the row of a created card: update, archive, detach; the row of an unsent one: export only' );
		$this->assertStringContainsString( '<span class="woodev-mp-btn__text">Экспортировать</span>', $html, 'a card that was not sent is «Экспортировать»' );
		$this->assertStringContainsString( '<span class="woodev-mp-btn__text">Обновить карточку</span>', $html );
	}

	public function test_a_variable_product_offers_the_export_only_while_a_variation_has_no_card(): void {
		$one_unsent = $this->parent_block();
		$html       = $this->render_tab( [ $one_unsent ], [ 'export' => 't1', 'archive' => 't2', 'detach' => 't3' ] );

		$this->assertStringContainsString( 'data-woodev-mp-op="export"', $html, 'a variation without a card: the product exports' );
		$this->assertStringContainsString( '<span class="woodev-mp-btn__text">Экспортировать</span>', $html );
		$this->assertStringContainsString( 'Кнопка «Экспортировать» проверит все варианты сразу', $html );
	}

	public function test_a_variable_product_whose_every_variation_has_its_card_has_no_export_button_but_keeps_archive_and_detach(): void {
		$block               = $this->parent_block();
		$block['any_unsent'] = false;
		$html                = $this->render_tab( [ $block ], [ 'export' => 't1', 'archive' => 't2', 'detach' => 't3' ] );

		$this->assertStringNotContainsString( 'data-woodev-mp-op="export"', $html, 'every card exists: nothing to export at the product level' );
		$this->assertStringNotContainsString( 'Экспортировать</span>', $html );
		$this->assertStringContainsString( 'data-woodev-mp-op="archive"', $html );
		$this->assertStringContainsString( 'data-woodev-mp-op="detach"', $html );
		$this->assertStringNotContainsString( 'Кнопка «Экспортировать»', $html, 'the help does not name a button that is not there' );
		$this->assertStringContainsString( 'затем нажмите нужную кнопку', $html );
	}

	public function test_a_variable_product_without_any_link_in_the_account_offers_the_export_for_the_whole_product(): void {
		$block                = $this->parent_block();
		$block['any_created'] = false;
		$block['any_linked']  = false;
		$block['any_unsent']  = true;
		$html                 = $this->render_tab( [ $block ], [ 'export' => 't1', 'archive' => 't2', 'detach' => 't3' ] );

		$this->assertStringContainsString( 'data-woodev-mp-op="export"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="archive"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="detach"', $html );
	}

	public function test_the_export_follows_the_account_the_block_belongs_to(): void {
		$linked               = $this->parent_block();
		$linked['any_unsent'] = false;
		$other                = $this->parent_block();
		$other['account']     = new Account( 5, 'fake', 'Другой', true );
		$other['any_created'] = false;
		$other['any_linked']  = false;
		$html                 = $this->render_tab( [ $linked, $other ], [ 'export' => 't1', 'archive' => 't2', 'detach' => 't3' ] );

		$this->assertSame( 0, preg_match( '#data-woodev-mp-op="export"[^>]*data-account="3"#', $html ), 'the account whose cards all exist has no export' );
		$this->assertSame( 1, preg_match( '#data-woodev-mp-op="export"[^>]*data-account="5"#', $html ), 'the account with unsent variations has it' );
	}

	public function test_an_imported_simple_product_is_not_offered_as_a_first_export(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'export' => 't1' ] );

		$this->assertStringNotContainsString( '<span class="woodev-mp-btn__text">Экспортировать</span>', $html, 'the card exists on the marketplace' );
		$this->assertStringContainsString( '<span class="woodev-mp-btn__text">Обновить карточку</span>', $html );
	}

	public function test_without_the_variations_actions_the_table_has_no_ticks_no_select_and_no_buttons(): void {
		$html = $this->render_tab( [ $this->parent_block() ], [ 'export' => 't1' ] );

		$this->assertStringNotContainsString( 'woodev-mp-variants__check', $html );
		$this->assertStringNotContainsString( 'woodev-mp-variants__select', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-vop', $html );
		$this->assertStringNotContainsString( 'Действия</th>', $html );
	}

	public function test_a_variation_has_the_buttons_of_a_simple_product_acting_on_itself(): void {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'loop'         => 0,
				'parent_id'    => 90,
				'variations'   => $this->variation_facts(),
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, 'data-woodev-mp-vop=' ), 'update card, archive, unlink' );
		$this->assertStringContainsString( 'data-woodev-mp-vop="archive" data-action="woodev_fake_variations" data-nonce="var-nonce" data-product="90" data-variation="41" data-account="3" data-confirm="Точно архивировать на Fake? Нажмите ещё раз."', $html, 'the request names the variable product and this variation' );
		$this->assertStringContainsString( 'class="woodev-mp-buttons__row button-group"', $html );
		$this->assertStringContainsString( '<span class="woodev-mp-result woodev-mp-note" role="status" aria-live="polite"></span>', $html );

		$facts            = $this->variation_facts();
		$facts['buttons'] = [ 'detach' ];

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'loop'         => 0,
				'parent_id'    => 90,
				'variations'   => $facts,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);

		$this->assertSame( 1, substr_count( (string) ob_get_clean(), 'data-woodev-mp-vop=' ), 'a button the user may not press is not offered' );
	}

	/**
	 * A block whose article is still editable (the card was not sent).
	 *
	 * @return array<string,mixed>
	 */
	private function editable_block(): array {
		$block                    = $this->block();
		$block['ids']['editable'] = true;
		$block['ids']['offer_id'] = '';

		return $block;
	}

	/**
	 * Renders the tab.
	 *
	 * @param array<int,array<string,mixed>> $blocks   Blocks.
	 * @param array<string,string>           $ops      Nonces of the buttons.
	 * @param bool                           $oversize Whether the marketplace has an oversize flag.
	 * @param array<string,mixed>            $extra    Keys of the screen to add or replace.
	 * @param bool                           $renames  Whether the adapter changes the article of a sent card.
	 * @return string
	 */
	private function render_tab( array $blocks, array $ops, bool $oversize = false, array $extra = [], bool $renames = false ): string {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ), $oversize, true, $renames ) )->render_tab(
			array_merge(
				[
					'blocks'       => $blocks,
					'nonce'        => 'save-nonce',
					'ops'          => $ops,
					'link_scope'   => '',
					'settings_url' => 'https://example.test/settings',
					'mappings_url' => 'https://example.test/mappings',
				],
				$extra
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * What the product tab hands the view for a variable product's variations.
	 *
	 * @return array<string,mixed>
	 */
	private function variation_facts(): array {
		return [
			'action'  => 'woodev_fake_variations',
			'nonce'   => 'var-nonce',
			'select'  => [
				'export'        => 'Экспортировать / Обновить карточку',
				'sync_stock_on' => 'Включить синхронизацию остатков',
			],
			'buttons' => [ 'export', 'archive', 'detach' ],
		];
	}

	/**
	 * Builds the block of a variable product with two variants: S (card created) and M (not sent).
	 *
	 * @return array<string,mixed>
	 */
	private function parent_block(): array {
		$block                = $this->block();
		$block['kind']        = 'parent';
		$block['counts']      = [];
		$block['any_created'] = true;
		$block['any_linked']  = true;
		$block['any_unsent']  = true;
		$row                  = [
			'id'          => 5,
			'name'        => 'S',
			'image'       => 'https://example.test/s.png',
			'badge'       => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'offer_id'    => 'ART-S',
			'planned'     => false,
			'is_new'      => false,
			'can_archive' => true,
			'can_detach'  => true,
			'site_stock'  => '500',
			'stock'       => '480',
			'site_price'  => '1990',
			'price'       => '',
			'currency'    => 'RUB',
		];
		$block['rows']        = [
			$row,
			array_merge(
				$row,
				[
					'id'          => 6,
					'name'        => 'M',
					'badge'       => Card_State_Badge::of( Link_Record::STATE_UNLINKED ),
					'offer_id'    => 'ART-M',
					'is_new'      => true,
					'can_archive' => false,
					'can_detach'  => false,
					'site_stock'  => '',
					'stock'       => '0',
					'site_price'  => '99.50',
					'price'       => '99.5000',
				]
			),
		];

		return $block;
	}

	/**
	 * Builds the block of an offer of account 3 whose card exists.
	 *
	 * @return array<string,mixed>
	 */
	private function block(): array {
		return [
			'kind'         => 'offer',
			'product_id'   => 41,
			'account'      => new Account( 3, 'fake', 'Основной', true ),
			'link_id'      => 0,
			'state'        => Link_Record::STATE_CREATED,
			'badge'        => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'remote_error' => '',
			'ids'          => [
				'editable' => false,
				'renamable'    => false,
				'pending'      => '',
				'rename_error' => '',
				'offer_id' => 'ART-1',
				'auto'     => 'ART-1',
				'summary'  => '',
				'card_url' => '',
			],
			'validation'   => [
				'errors'   => [],
				'warnings' => [],
				'failed'   => false,
			],
			'sync_stock'   => true,
			'sync_price'   => true,
			'oversize'     => false,
			'warehouse'    => [
				'selected' => null,
				'options'  => [
					[
						'value' => '',
						'label' => '',
					],
				],
				'default'  => '',
			],
			'price'        => [
				'override' => [],
				'computed' => [
					'price'     => '',
					'old_price' => '',
					'min_price' => '',
				],
				'currency' => 'RUB',
				'problem'  => '',
			],
			'mapping'      => [
				'selected' => null,
				'options'  => [],
				'resolved' => '',
			],
			'reasons'      => [
				'visible' => false,
				'list'    => [],
			],
			'can_detach'   => true,
			'can_archive'  => true,
		];
	}
}
