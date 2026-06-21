<?php
/**
 * Tests for the competitor notice renderers (s28).
 *
 * Admin_Notice_Renderer: render() forwards content + note name to the plugin's
 * Woodev_Admin_Notice_Handler::add_admin_notice(); delete() does not call the
 * handler. WC_Admin_Notes_Renderer: delete() is a safe no-op when WC is absent;
 * a separate-process test with minimal WC stubs covers the create path.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Admin_Notice_Renderer;
use Woodev\Framework\Competitor\Competitor_Rule;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-notification-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-admin-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-notes-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-wc-admin-notes-renderer.php';

class CompetitorRendererTest extends TestCase {

	public function test_admin_notice_renderer_forwards_content_and_name(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );

		$handler = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$handler->shouldReceive( 'add_admin_notice' )
			->once()
			->with( 'BODY', $rule->get_note_name() );

		$renderer = new Admin_Notice_Renderer( $handler );
		$renderer->render( $rule, [ 'title' => 'T', 'content' => 'BODY', 'actions' => [] ] );

		$this->assertTrue( true ); // Mockery verifies the expectation on tearDown.
	}

	public function test_admin_notice_renderer_appends_primary_action_link(): void {

		\Brain\Monkey\Functions\when( 'esc_url' )->returnArg();
		\Brain\Monkey\Functions\when( 'esc_html' )->returnArg();

		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );

		$captured = '';
		$handler  = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$handler->shouldReceive( 'add_admin_notice' )->once()->andReturnUsing(
			static function ( $content ) use ( &$captured ) {
				$captured = $content;
			}
		);

		$renderer = new Admin_Notice_Renderer( $handler );
		$renderer->render(
			$rule,
			[
				'content' => 'BODY',
				'actions' => [
					[ 'name' => 'go', 'label' => 'Перейти', 'url' => 'https://woodev.ru/x', 'primary' => true ],
					[ 'name' => 'dismiss', 'label' => 'Скрыть' ],
				],
			]
		);

		$this->assertStringContainsString( 'BODY', $captured );
		$this->assertStringContainsString( '<a href="https://woodev.ru/x">Перейти</a>', $captured );
	}

	public function test_admin_notice_renderer_delete_is_noop(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );

		$handler = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$handler->shouldNotReceive( 'add_admin_notice' );

		$renderer = new Admin_Notice_Renderer( $handler );
		$renderer->delete( $rule );

		$this->assertTrue( true );
	}

	public function test_wc_renderer_delete_is_safe_noop_when_wc_absent(): void {

		$rule = new Competitor_Rule( [ 'detect' => 'yandex-go-delivery.php', 'mode' => 'conflict' ] );

		// With the WC Notes class absent in unit context, delete() returns early
		// without touching the (also-absent) WC Notes API.
		$renderer = new \Woodev\Framework\Competitor\WC_Admin_Notes_Renderer( 'woodev-test-plugin' );
		$renderer->delete( $rule );

		$this->assertTrue( true );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wc_renderer_creates_note_when_wc_present(): void {

		// Load a minimal WC Admin Notes Note stub so class_exists( Note::class ) is
		// true and the renderer can build a note. Isolated to this process.
		require_once dirname( __DIR__ ) . '/_stubs/wc-admin-notes-note.php';

		require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-notes-helper.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-notification-handler.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-wc-admin-notes-renderer.php';

		\Brain\Monkey\Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);

		$rule = new \Woodev\Framework\Competitor\Competitor_Rule(
			[ 'detect' => 'cdek.php', 'mode' => 'recommend' ]
		);

		$renderer = new \Woodev\Framework\Competitor\WC_Admin_Notes_Renderer( 'woodev-test-plugin' );
		$renderer->render(
			$rule,
			[
				'title'   => 'Заголовок',
				'content' => 'Тело',
				'type'    => \Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_UPDATE,
				'image'   => '',
				'actions' => [ [ 'name' => 'go', 'label' => 'Перейти', 'url' => 'https://x', 'primary' => true ] ],
			]
		);

		$this->assertNotEmpty( \Automattic\WooCommerce\Admin\Notes\Note::$saved );
		$saved = \Automattic\WooCommerce\Admin\Notes\Note::$saved[0];
		// Note name is namespaced by the plugin source to avoid cross-plugin collision.
		$this->assertSame( 'woodev-test-plugin-woodev-competitor-recommend-cdek-php', $saved['name'] );
		$this->assertSame( 'Заголовок', $saved['title'] );
		$this->assertSame( 'woodev-test-plugin', $saved['source'] );
	}
}
