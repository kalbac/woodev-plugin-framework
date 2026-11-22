<?php
/**
 * A deleted account's options go with it, the history cutoff included (#1925).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Deletion\Deletion_Module;
use Woodev\Framework\Marketplace\Jobs\Posting_History_Cutoffs;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * Runs the options step of the account deletion — the closure `Deletion_Module::forget_options()` builds — on SQLite and an option
 * table double. The cutoff of a deleted account must not be read by a later account that is given the same id.
 */
final class AccountOptionsForgottenTest extends TestCase {
	private const PLUGIN  = 'fake-seller';
	private const DELETED = 7;
	private const KEPT    = 8;

	/**
	 * The options as the test's doubles keep them.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Installs the option doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = (string) $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * Deleting an account removes its cutoff and only its own; an account added later under the same id has none, so its first poll
	 * records a cutoff of its own.
	 *
	 * @return void
	 */
	public function test_the_cutoff_goes_with_the_account_and_a_reused_id_starts_fresh(): void {
		$cutoffs = new Posting_History_Cutoffs( self::PLUGIN );
		$cutoffs->remember( self::DELETED, 1795000000 );
		$cutoffs->remember( self::KEPT, 1796000000 );

		$this->forget_options( self::DELETED );

		$this->assertNull( $cutoffs->get( self::DELETED ), 'the deleted account\'s cutoff is gone' );
		$this->assertSame( 1796000000, $cutoffs->get( self::KEPT ), 'another account keeps its own' );

		$cutoffs->remember( self::DELETED, 1799000000 );
		$this->assertSame( 1799000000, $cutoffs->get( self::DELETED ), 'the account that reuses the id records a fresh cutoff, not the old one' );
	}

	/**
	 * Runs the options step for an account.
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	private function forget_options( int $account_id ): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );

		$schema = Sqlite_Tables::schema();
		$step   = new \ReflectionMethod( Deletion_Module::class, 'forget_options' );
		if ( PHP_VERSION_ID < 80100 ) {
			$step->setAccessible( true ); // Needed before PHP 8.1, deprecated from 8.5.
		}

		$forget = $step->invoke(
			null,
			self::PLUGIN,
			new Wpdb_Catalog_Cache( $db, $schema->table_name( 'catalog_cache', $db->prefix ) ),
			new Wpdb_Card_Store( $db, $schema->table_name( 'cards', $db->prefix ), $schema->table_name( 'links', $db->prefix ), 'woodev_' . self::PLUGIN . '_' )
		);

		$forget( $account_id );
	}
}
