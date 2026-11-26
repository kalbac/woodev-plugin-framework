<?php
/**
 * A marketplace's own word for a posting.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Orders_Analytics_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Definition;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Allowed_Actions;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Preview;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Framework\Marketplace\Settings\Posting_Wording;
use Woodev\Framework\Marketplace\Settings\Settings_Descriptor;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Settings\Posting_Noun
 */
final class PostingNounTest extends TestCase {
	private const FORMS = [
		'one'        => 'сборочное задание',
		'of_one'     => 'сборочного задания',
		'to_one'     => 'сборочному заданию',
		'with_one'   => 'со сборочным заданием',
		'many'       => 'сборочные задания',
		'of_many'    => 'сборочных заданий',
		'about_many' => 'о сборочных заданиях',
	];

	public function test_a_descriptor_without_its_own_wording_says_shipment(): void {
		$noun = Posting_Noun::for_descriptor( Mockery::mock( Settings_Descriptor::class ) );

		$this->assertSame( 'отправление', $noun->one() );
		$this->assertSame( 'с отправлением', $noun->with_one() );
		$this->assertSame( 'об отправлениях', $noun->about_many() );
	}

	public function test_a_descriptor_with_its_own_wording_gives_its_noun(): void {
		$own        = new Posting_Noun( self::FORMS );
		$descriptor = Mockery::mock( Settings_Descriptor::class, Posting_Wording::class );
		$descriptor->shouldReceive( 'posting_noun' )->andReturn( $own );

		$this->assertSame( $own, Posting_Noun::for_descriptor( $descriptor ) );
	}

	public function test_a_noun_without_one_of_its_forms_is_refused(): void {
		$forms           = self::FORMS;
		$forms['to_one'] = ' ';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'to_one' );

		new Posting_Noun( $forms );
	}

	public function test_a_form_is_capitalised_to_start_a_sentence(): void {
		$this->assertSame( 'Сборочное задание', Posting_Noun::capitalised( 'сборочное задание' ) );
	}

	public function test_the_actions_of_an_adapter_are_refused_in_its_own_words(): void {
		$descriptor = Mockery::mock( Settings_Descriptor::class, Posting_Wording::class );
		$descriptor->shouldReceive( 'posting_noun' )->andReturn( new Posting_Noun( self::FORMS ) );
		$adapter = Mockery::mock( Marketplace_Adapter::class, Posting_Allowed_Actions::class );
		$adapter->shouldReceive( 'settings_descriptor' )->andReturn( $descriptor );
		$adapter->shouldReceive( 'allowed_posting_actions' )->andReturn( [] );
		$gate  = Posting_Action_Gate::for_adapter( $adapter );
		$order = Mockery::mock( 'WC_Order' );

		$this->assertSame( 'Маркетплейс сейчас не разрешает отменить это сборочное задание — например, оно уже передано в доставку.', $gate->cancel_reason( $order ) );
		$this->assertSame( 'Маркетплейс ещё не выдал этикетку для этого сборочного задания — она станет доступна, когда сборочное задание будет собрано.', $gate->label_reason( $order, 'new', false ) );
		$this->assertSame( 'Сборочное задание завершено — этикетка не нужна.', $gate->label_reason( $order, 'delivered', true ) );
	}

	public function test_the_orders_table_and_the_analytics_tab_use_the_noun(): void {
		Functions\when( 'wp_date' )->justReturn( '2026-11-26' );

		$noun       = new Posting_Noun( self::FORMS );
		$definition = Site_Orders_Definition::build( [], [], [ Site_Orders_Definition::CANCEL ], $noun );
		$columns    = array_column( $definition['columns'], 'label', 'id' );
		$filters    = array_column( $definition['filters'], 'label', 'id' );
		$analytics  = Orders_Analytics_Controller::messages( $noun );

		$this->assertSame( 'Сборочное задание', $columns['posting'] );
		$this->assertSame( 'Поиск по сборочному заданию или ID заказа', $filters['q'] );
		$this->assertSame( 'Состояние сборочного задания', $filters['state'] );
		$this->assertSame( 'Отменить сборочное задание', array_column( $definition['row_actions'], 'label', 'id' )[ Site_Orders_Definition::CANCEL ] );
		$this->assertSame( 'Сборочных заданий пока нет.', $analytics['statusesEmpty'] );
		$this->assertSame( 'Сборочные задания по статусам', $analytics['statuses'] );
		$this->assertStringStartsWith( 'Сборочные задания, которые ждут', Orders_Analytics_Controller::stuck_hint( 24, $noun ) );
		$this->assertStringNotContainsStringIgnoringCase( 'отправлени', (string) json_encode( [ $definition, $analytics ], JSON_UNESCAPED_UNICODE ) );
	}

	public function test_the_account_deletion_names_what_it_removes_by_the_noun(): void {
		$preview = new Account_Deletion_Preview(
			[
				'postings' => static function (): int {
					return 3;
				},
			],
			Effective_Settings::for_adapter( 'wildberries' ),
			false,
			new Posting_Noun( self::FORMS )
		);

		Functions\when( 'get_option' )->justReturn( [] );

		$answer = $preview->describe( new Account( 1, 'wildberries', 'Основной', true, [], null, [] ) );

		$this->assertSame( 'Сборочные задания', array_column( $answer['counts'], 'label', 'key' )['postings'] );
		$this->assertStringContainsString( 'записи о сборочных заданиях будут удалены', $answer['enabled_note'] );
		$this->assertStringNotContainsStringIgnoringCase( 'отправлени', (string) json_encode( $answer, JSON_UNESCAPED_UNICODE ) );
	}
}
