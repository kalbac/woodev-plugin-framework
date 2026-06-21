<?php
/**
 * Tests for the Woodev\Framework\Competitor\Competitor_Notification_Handler engine (s28).
 *
 * Covers: detection any-match; recommend suppression when our plugin active +
 * degrade when our_plugin_file omitted; auto-delete when competitor inactive;
 * default template interpolation + per-rule override; smart recommend link
 * target (connected+owned → extensions page; else → product URL; degraded → URL);
 * conflict action = nonce'd deactivate link; opt-in wiring on Woodev_Plugin.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Competitor_Notification_Handler;
use Woodev\Framework\Competitor\Competitor_Notice_Renderer;
use Woodev\Framework\Competitor\Competitor_Rule;
use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-notification-handler.php';

/**
 * A spy renderer capturing render/delete calls.
 */
class Spy_Renderer implements Competitor_Notice_Renderer {
	/** @var array<int,array{rule:Competitor_Rule,note:array}> */
	public array $rendered = [];
	/** @var array<int,Competitor_Rule> */
	public array $deleted = [];

	public function render( Competitor_Rule $rule, array $note ): void {
		$this->rendered[] = [ 'rule' => $rule, 'note' => $note ];
	}
	public function delete( Competitor_Rule $rule ): void {
		$this->deleted[] = $rule;
	}
}

/**
 * Test subclass: injectable rules + spy renderer + overridable account seam.
 */
class Test_Competitor_Handler extends Competitor_Notification_Handler {
	/** @var array<int,array<string,mixed>> */
	public array $rules = [];
	public Spy_Renderer $spy;
	public bool $force_connected = false;

	protected function get_competitor_rules(): array {
		return $this->rules;
	}
	protected function get_renderer(): Competitor_Notice_Renderer {
		return $this->spy;
	}
	protected function is_account_connected(): bool {
		return $this->force_connected;
	}
}

class CompetitorNotificationHandlerTest extends TestCase {

	private function make_plugin( array $active ) {

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'is_plugin_active' )->andReturnUsing(
			static fn( $slug ) => in_array( $slug, $active, true )
		);
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( 'woodev-test-plugin' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn(
			Mockery::mock( 'Woodev_Admin_Notice_Handler' )
		);

		return $plugin;
	}

	private function make_handler( $plugin, array $rules ): Test_Competitor_Handler {
		$handler        = new Test_Competitor_Handler( $plugin );
		$handler->spy   = new Spy_Renderer();
		$handler->rules = $rules;
		return $handler;
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'http://site/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
	}

	public function test_detection_any_match_renders(): void {
		$plugin  = $this->make_plugin( [ 'b.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => [ 'a.php', 'b.php' ], 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$this->assertCount( 1, $handler->spy->rendered );
		$this->assertCount( 0, $handler->spy->deleted );
	}

	public function test_inactive_competitor_is_deleted(): void {
		$plugin  = $this->make_plugin( [] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'a.php', 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$this->assertCount( 0, $handler->spy->rendered );
		$this->assertCount( 1, $handler->spy->deleted );
	}

	public function test_recommend_suppressed_when_our_plugin_active(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php', 'woocommerce-edostavka.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_plugin_file' => 'woocommerce-edostavka.php',
				],
			]
		);
		$handler->run();
		$this->assertCount( 0, $handler->spy->rendered );
		$this->assertCount( 1, $handler->spy->deleted );
	}

	public function test_recommend_degrades_when_our_plugin_file_omitted(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'cdek.php', 'mode' => 'recommend', 'our_url' => 'https://woodev.ru/x' ] ]
		);
		$handler->run();
		$this->assertCount( 1, $handler->spy->rendered );
	}

	public function test_default_recommend_template_interpolates_names(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'competitor_name' => 'CDEKDelivery',
					'our_name'        => 'Интеграция СДЭК',
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note = $handler->spy->rendered[0]['note'];
		$this->assertStringContainsString( 'CDEKDelivery', $note['content'] );
		$this->assertStringContainsString( 'Интеграция СДЭК', $note['content'] );
	}

	public function test_per_rule_content_override_wins(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'  => 'cdek.php',
					'mode'    => 'recommend',
					'title'   => 'CUSTOM TITLE',
					'content' => 'CUSTOM BODY',
					'our_url' => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note = $handler->spy->rendered[0]['note'];
		$this->assertSame( 'CUSTOM TITLE', $note['title'] );
		$this->assertSame( 'CUSTOM BODY', $note['content'] );
	}

	public function test_recommend_link_target_degraded_to_product_url(): void {
		// Not connected (default) → primary action URL is our_url.
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_download_id' => 42,
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->run();
		$note    = $handler->spy->rendered[0]['note'];
		$primary = $this->primary_action( $note );
		$this->assertSame( 'https://woodev.ru/x', $primary['url'] );
	}

	public function test_recommend_link_target_extensions_page_when_connected_and_owned(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'purchased' => [ 42 ] ] );

		$plugin                 = $this->make_plugin( [ 'cdek.php' ] );
		$handler                = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_download_id' => 42,
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->force_connected = true;

		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertStringContainsString( 'page=woodev-extensions', $primary['url'] );
	}

	public function test_recommend_link_target_product_url_when_connected_but_not_owned(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'purchased' => [ 99 ] ] );

		$plugin                 = $this->make_plugin( [ 'cdek.php' ] );
		$handler                = $this->make_handler(
			$plugin,
			[
				[
					'detect'          => 'cdek.php',
					'mode'            => 'recommend',
					'our_download_id' => 42,
					'our_url'         => 'https://woodev.ru/x',
				],
			]
		);
		$handler->force_connected = true;

		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertSame( 'https://woodev.ru/x', $primary['url'] );
	}

	public function test_conflict_primary_action_is_deactivate_link(): void {
		$plugin  = $this->make_plugin( [ 'yandex-go-delivery.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => 'yandex-go-delivery.php', 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertStringContainsString( 'plugins.php', $primary['url'] );
		$this->assertStringContainsString( 'action=deactivate', $primary['url'] );
		$this->assertStringContainsString( 'NONCE', $primary['url'] );
	}

	public function test_conflict_deactivate_link_targets_the_active_slug_not_the_first(): void {
		// Rule lists two competitors; only the SECOND is active. The deactivate
		// link must target the active one, not the first declared (H-2).
		$plugin  = $this->make_plugin( [ 'second-rival.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[ [ 'detect' => [ 'first-rival.php', 'second-rival.php' ], 'mode' => 'conflict' ] ]
		);
		$handler->run();
		$primary = $this->primary_action( $handler->spy->rendered[0]['note'] );
		$this->assertStringContainsString( 'plugin=second-rival.php', $primary['url'] );
		$this->assertStringNotContainsString( 'first-rival.php', $primary['url'] );
	}

	public function test_invalid_rule_is_skipped_not_fatal(): void {
		$plugin  = $this->make_plugin( [ 'cdek.php' ] );
		$handler = $this->make_handler(
			$plugin,
			[
				[ 'detect' => 'cdek.php', 'mode' => 'bogus' ], // invalid → skipped
				[ 'detect' => 'cdek.php', 'mode' => 'conflict' ],
			]
		);
		$handler->run();
		$this->assertCount( 1, $handler->spy->rendered );
	}

	/** @param array<string,mixed> $note */
	private function primary_action( array $note ): array {
		foreach ( $note['actions'] as $action ) {
			if ( ! empty( $action['primary'] ) ) {
				return $action;
			}
		}
		return $note['actions'][0] ?? [];
	}
}
