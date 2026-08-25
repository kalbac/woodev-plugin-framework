<?php
/**
 * Setup Wizard finish actions tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Concrete wizard exposing finish-action methods for isolated testing.
 */
class Finish_Actions_Test_Wizard extends Setup_Wizard {

	/**
	 * Inject mock, skip parent wiring.
	 *
	 * @param \Woodev_Plugin $plugin plugin mock.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/** @return void */
	protected function register_steps(): void {}

	/** @return string */
	public function get_id(): string {
		return 'finish-test';
	}

	/**
	 * Proxy to get_finish_actions().
	 *
	 * @return array<int,array<string,string>>
	 */
	public function public_finish_actions(): array {
		return $this->get_finish_actions();
	}

	/**
	 * Proxy to get_finish_secondary_actions().
	 *
	 * @return array<int,array<string,string>>
	 */
	public function public_finish_secondary_actions(): array {
		return $this->get_finish_secondary_actions();
	}

	/**
	 * Proxy to get_bootstrap_data() to confirm finishSecondaryActions key.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->get_bootstrap_data();
	}

	/** @return array<string,array<string,mixed>> */
	protected function get_field_schema(): array {
		return [];
	}

	/** @return string */
	public function get_state(): string {
		return '';
	}
}

/**
 * Tests for B3: finish next-step cards and secondary actions.
 */
class SetupWizardFinishActionsTest extends TestCase {

	/**
	 * get_finish_actions() returns a documentation card when plugin has a docs URL.
	 *
	 * @return void
	 */
	public function test_finish_actions_contains_documentation_card(): void {
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( 'https://docs.example.com' );

		$wizard  = new Finish_Actions_Test_Wizard( $plugin );
		$actions = $wizard->public_finish_actions();

		$this->assertCount( 1, $actions );
		$card = $actions[0];

		$this->assertArrayHasKey( 'heading', $card );
		$this->assertArrayHasKey( 'title', $card );
		$this->assertArrayHasKey( 'description', $card );
		$this->assertArrayHasKey( 'actionLabel', $card );
		$this->assertArrayHasKey( 'url', $card );
		$this->assertSame( 'https://docs.example.com', $card['url'] );
	}

	/**
	 * get_finish_actions() returns empty array when no docs URL is set.
	 *
	 * @return void
	 */
	public function test_finish_actions_empty_when_no_docs_url(): void {
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );

		$wizard  = new Finish_Actions_Test_Wizard( $plugin );
		$actions = $wizard->public_finish_actions();

		$this->assertSame( [], $actions );
	}

	/**
	 * get_finish_secondary_actions() includes settings card when get_settings_url() returns a URL.
	 *
	 * @return void
	 */
	public function test_secondary_actions_settings_card(): void {
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( 'https://example.com/settings' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard  = new Finish_Actions_Test_Wizard( $plugin );
		$actions = $wizard->public_finish_secondary_actions();

		$this->assertNotEmpty( $actions );

		$settings_card = null;
		foreach ( $actions as $action ) {
			if ( 'settings' === $action['icon'] ) {
				$settings_card = $action;
				break;
			}
		}

		$this->assertNotNull( $settings_card, 'Settings card not found in secondary actions' );
		$this->assertArrayHasKey( 'label', $settings_card );
		$this->assertArrayHasKey( 'url', $settings_card );
		$this->assertSame( 'https://example.com/settings', $settings_card['url'] );
	}

	/**
	 * get_finish_secondary_actions() includes review card when get_reviews_url() returns a URL.
	 *
	 * @return void
	 */
	public function test_secondary_actions_review_card(): void {
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( 'https://example.com/reviews' );

		$wizard  = new Finish_Actions_Test_Wizard( $plugin );
		$actions = $wizard->public_finish_secondary_actions();

		$review_card = null;
		foreach ( $actions as $action ) {
			if ( 'review' === $action['icon'] ) {
				$review_card = $action;
				break;
			}
		}

		$this->assertNotNull( $review_card, 'Review card not found in secondary actions' );
		$this->assertArrayHasKey( 'label', $review_card );
		$this->assertArrayHasKey( 'url', $review_card );
		$this->assertSame( 'https://example.com/reviews', $review_card['url'] );
	}

	/**
	 * get_finish_secondary_actions() returns empty array when both URL methods return empty.
	 *
	 * @return void
	 */
	public function test_secondary_actions_empty_when_urls_are_empty(): void {
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard  = new Finish_Actions_Test_Wizard( $plugin );
		$actions = $wizard->public_finish_secondary_actions();

		$this->assertSame( [], $actions );
	}

	/**
	 * Bootstrap data exposes finishSecondaryActions key.
	 *
	 * @return void
	 */
	public function test_bootstrap_data_has_finish_secondary_actions_key(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'N' );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard = new Finish_Actions_Test_Wizard( $plugin );
		$data   = $wizard->data();

		$this->assertArrayHasKey( 'finishSecondaryActions', $data );
		$this->assertIsArray( $data['finishSecondaryActions'] );
	}
}
