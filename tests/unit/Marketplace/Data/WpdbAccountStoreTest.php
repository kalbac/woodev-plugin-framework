<?php
/**
 * The accounts table: encrypted secrets, the merchant's switch, health, fences.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Credential_Fields;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the store's SQL on SQLite, over the real columns of the schema, with the values of a fake adapter (an identifier and one
 * secret; a second fixture has two secrets).
 */
final class WpdbAccountStoreTest extends TestCase {
	private const NOW        = 1794139200; // 2026-11-08 12:00:00 UTC.
	private const IDENTIFIER = 'cabinet-4417';
	private const TOKEN      = 'tok-0000-1111-2222-3333-secret';
	private const SIGNING    = 'sig-9999-8888-7777-6666-secret';

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Cipher.
	 *
	 * @var Secret_Cipher
	 */
	private $cipher;

	/**
	 * Store with one secret.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $store;

	/**
	 * The clock the store reads.
	 *
	 * @var int
	 */
	private $now = self::NOW;

	/**
	 * Opens a database with the tables and builds the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->now    = self::NOW;
		$this->db     = new Sqlite_Wpdb();
		$this->cipher = new Secret_Cipher( 'test-salt-secure-auth' );
		Sqlite_Tables::install( $this->db );
		$this->store = $this->store_with( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ) );
	}

	/**
	 * Restores Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An account comes back as it went in: credentials, columns and JSON knobs read as `Account::settings()`.
	 *
	 * @return void
	 */
	public function test_an_account_round_trips(): void {
		$id = $this->seed(
			[
				'warehouse_id'    => 1020005031696180,
				'stock_mode'      => Wpdb_Account_Store::STOCK_MARKETPLACE,
				'price_direction' => Wpdb_Account_Store::PRICE_OFF,
				'orders_enabled'  => false,
				'import_enabled'  => true,
				'currency'        => 'RUB',
				'price_rule'      => [
					'markup_kind' => 'percent',
					'markup'      => '15',
				],
			]
		);

		$account = $this->store->get( $id );

		$this->assertNotNull( $account );
		$this->assertSame( 'fake', $account->adapter_id() );
		$this->assertSame( 'Основной', $account->name() );
		$this->assertTrue( $account->is_enabled() );
		$this->assertSame( self::IDENTIFIER, $account->credentials()['cabinet_ref'] );
		$this->assertSame( self::TOKEN, $account->credentials()['api_token'] );
		$this->assertFalse( $account->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] );
		$this->assertSame( 1020005031696180, $account->setting( 'warehouse_id' ) );
		$this->assertSame( 'marketplace', $account->setting( 'stock_mode' ) );
		$this->assertSame( 'off', $account->setting( 'price_direction' ) );
		$this->assertFalse( $account->setting( 'orders_enabled' ) );
		$this->assertTrue( $account->setting( 'import_enabled' ) );
		$this->assertSame( 'RUB', $account->setting( 'currency' ) );
		$this->assertSame(
			[
				'markup_kind' => 'percent',
				'markup'      => '15',
			],
			$account->setting( 'price_rule' )
		);
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $account->setting( 'notification_token' ) );
	}

	/**
	 * The table defaults apply to an account saved without knobs: orders on, import off, stock from the site, prices to the
	 * marketplace.
	 *
	 * @return void
	 */
	public function test_the_defaults_of_a_new_account(): void {
		$account = $this->store->get( $this->seed() );

		$this->assertTrue( $account->setting( 'orders_enabled' ) );
		$this->assertFalse( $account->setting( 'import_enabled' ) );
		$this->assertSame( Wpdb_Account_Store::STOCK_SITE, $account->setting( 'stock_mode' ) );
		$this->assertSame( Wpdb_Account_Store::PRICE_TO_MARKETPLACE, $account->setting( 'price_direction' ) );
		$this->assertNull( $account->setting( 'warehouse_id' ) );
	}

	/**
	 * The secrets are encrypted at rest: no column of the row, and no statement that wrote it, holds a secret in clear.
	 *
	 * @return void
	 */
	public function test_the_secrets_are_encrypted_at_rest(): void {
		$id  = $this->seed();
		$row = $this->raw_row( $id );

		$this->assertStringStartsWith( Secret_Cipher::PREFIX, $row['secret_enc'] );
		$this->assertSame( [ 'api_token' => self::TOKEN ], json_decode( (string) $this->cipher->decrypt( $row['secret_enc'] ), true ) );
		$this->assertSame( Secret_Cipher::fingerprint( (string) json_encode( [ 'api_token' => self::TOKEN ] ) ), $row['secret_fingerprint'] );
		$this->assertStringNotContainsString( self::TOKEN, implode( '|', array_map( 'strval', $row ) ) );
		$this->assertStringNotContainsString( self::TOKEN, implode( "\n", $this->db->statements ) );
	}

	/**
	 * Acceptance «encrypted secrets never in wp_options»: whatever the store does with secrets, it never touches the options table
	 * and keeps them out of the settings JSON.
	 *
	 * @return void
	 */
	public function test_secrets_never_reach_the_options_table(): void {
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'set_transient' )->never();

		$id = $this->seed( [ 'currency' => 'RUB' ] );
		$this->store->replace_secrets( $id, [ 'api_token' => 'rotated-token-0000' ] );
		$this->store->set_health_for_credentials(
			$id,
			[
				'cabinet_ref' => self::IDENTIFIER,
				'api_token'   => 'rotated-token-0000',
			],
			new Health_Result( Health_Result::OK, 'ok', null, [ 'limits' => [ 'total' => [ 'usage' => 1 ] ] ] )
		);
		$this->store->update_settings(
			$id,
			static function ( array $settings ): array {
				$settings['note'] = 'x';

				return $settings;
			}
		);

		$row = $this->raw_row( $id );
		$this->assertStringNotContainsString( 'rotated-token-0000', (string) $row['settings'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) $row['settings'] );
		$this->assertStringNotContainsString( 'rotated-token-0000', implode( "\n", $this->db->statements ) );
	}

	/**
	 * `is_enabled` is written by an insert and by `set_enabled()` only: saving an existing account, rotating its secrets and
	 * recording a health outcome leave the merchant's switch alone, whatever the `Account` object says.
	 *
	 * @return void
	 */
	public function test_is_enabled_is_never_written_by_code(): void {
		$id = $this->seed();
		$this->assertSame( '1', (string) $this->raw_row( $id )['is_enabled'] );
		$this->db->statements = [];

		$this->store->save( $this->account( $id, 'Другое имя', false, 'rotated-token-0000', [ 'stock_mode' => 'marketplace' ] ) );
		foreach ( [ Health_Result::AUTH_FAILED, Health_Result::ROLE_MISSING, Health_Result::UNREACHABLE, Health_Result::KEY_EXPIRED, Health_Result::OK ] as $status ) {
			$this->store->set_health( $id, new Health_Result( $status, 'message' ) );
		}

		$this->assertSame( '1', (string) $this->raw_row( $id )['is_enabled'], 'the row still says the merchant switched it on' );
		$this->assertSame( [], $this->writes_to( 'is_enabled' ), 'no statement mentions the switch' );
		$this->assertSame( 'Другое имя', $this->raw_row( $id )['name'], 'the rest of the save went through' );
	}

	/**
	 * The merchant's toggle is the one writer of an existing row.
	 *
	 * @return void
	 */
	public function test_set_enabled_is_the_merchants_writer(): void {
		$id = $this->seed();

		$this->store->set_enabled( $id, false );
		$this->assertFalse( $this->store->get( $id )->is_enabled() );

		$this->store->set_enabled( $id, true );
		$this->assertTrue( $this->store->get( $id )->is_enabled() );
	}

	/**
	 * A new account starts as the merchant's form says; the schema default is off.
	 *
	 * @return void
	 */
	public function test_a_new_account_is_off_unless_the_form_said_otherwise(): void {
		$this->assertFalse( $this->store->get( $this->seed( [], false ) )->is_enabled() );
	}

	/**
	 * `all()` lists in id order, optionally only the switched-on accounts, and only for the store's adapter.
	 *
	 * @return void
	 */
	public function test_all_lists_by_adapter_and_switch(): void {
		$first  = $this->seed( [], true, '1', 'a' );
		$second = $this->seed( [], false, '2', 'b' );

		$this->assertSame( [ $first, $second ], $this->ids( $this->store->all( 'fake', false ) ) );
		$this->assertSame( [ $first ], $this->ids( $this->store->all( 'fake', true ) ) );
		$this->assertSame( [], $this->store->all( 'wildberries', false ) );
	}

	/**
	 * A health outcome is stored with the expiry and the currency; one that knows neither keeps what was stored.
	 *
	 * @return void
	 */
	public function test_set_health_keeps_an_expiry_the_result_does_not_know(): void {
		$id      = $this->seed();
		$expires = new \DateTimeImmutable( '2027-02-06T01:22:16Z' );

		$this->store->set_health( $id, new Health_Result( Health_Result::OK, 'Подключено.', $expires, [ 'currency' => 'RUB' ] ) );
		$this->store->set_health( $id, new Health_Result( Health_Result::UNREACHABLE, 'Маркетплейс не отвечает' ) );

		$health = $this->store->health( $id );
		$this->assertSame( Health_Result::UNREACHABLE, $health->status() );
		$this->assertSame( 'Маркетплейс не отвечает', $health->message() );
		$this->assertSame( $expires->getTimestamp(), $health->expires_at()->getTimestamp() );
		$this->assertSame( 'RUB', $health->extra()['currency'] );
		$this->assertSame( self::NOW, $health->extra()['checked_at']->getTimestamp() );
		$this->assertSame( $expires->getTimestamp(), $this->store->get( $id )->expires_at()->getTimestamp() );
	}

	/**
	 * Nothing is known about an account until it is checked.
	 *
	 * @return void
	 */
	public function test_health_is_null_before_the_first_check(): void {
		$this->assertNull( $this->store->health( $this->seed() ) );
		$this->assertNull( $this->store->health( 999 ) );
	}

	/**
	 * A new secret (or a new identifier) makes the stored outcome and expiry meaningless, so both are forgotten; saving the same
	 * secret again or with an empty one keeps them.
	 *
	 * @return void
	 */
	public function test_a_new_secret_forgets_the_old_health(): void {
		$id = $this->seed();
		$this->store->set_health( $id, new Health_Result( Health_Result::OK, 'Подключено.', new \DateTimeImmutable( '2027-02-06T01:22:16Z' ) ) );
		$settings = $this->store->get( $id )->settings();

		$this->store->save( $this->account( $id, 'Основной', true, self::TOKEN, $settings ) );
		$this->store->save( $this->account( $id, 'Основной', true, '', $settings ) );
		$this->assertNotNull( $this->store->health( $id ), 'the same secret keeps its health' );
		$this->assertSame( self::TOKEN, $this->store->get( $id )->credentials()['api_token'], 'an empty secret keeps the stored one' );

		$this->store->save( $this->account( $id, 'Основной', true, 'rotated-token-0000', $settings ) );

		$this->assertNull( $this->store->health( $id ) );
		$this->assertNull( $this->store->get( $id )->expires_at() );
		$this->assertSame( 'rotated-token-0000', $this->store->get( $id )->credentials()['api_token'] );
		$this->assertSame( Secret_Cipher::fingerprint( (string) json_encode( [ 'api_token' => 'rotated-token-0000' ] ) ), $this->raw_row( $id )['secret_fingerprint'] );
	}

	/**
	 * The adapter may declare several secrets: each is stored, an empty one keeps its stored value, and only the changed one moves.
	 *
	 * @return void
	 */
	public function test_several_secrets_are_stored_and_replaced_one_by_one(): void {
		$store = $this->store_with( new Credential_Fields( 'cabinet_ref', [ 'api_token', 'signing_key' ] ) );
		$id    = $store->save(
			new Account(
				0,
				'fake',
				'Два ключа',
				true,
				[
					'cabinet_ref' => self::IDENTIFIER,
					'api_token'   => self::TOKEN,
					'signing_key' => self::SIGNING,
				]
			)
		);

		$this->assertSame( self::SIGNING, $store->get( $id )->credentials()['signing_key'] );
		$this->assertStringNotContainsString( self::SIGNING, implode( '|', array_map( 'strval', $this->raw_row( $id ) ) ) );

		$this->assertTrue( $store->replace_secrets( $id, [ 'signing_key' => 'sig-new-0000' ] ) );
		$this->assertSame( self::TOKEN, $store->get( $id )->credentials()['api_token'], 'the secret that was not given stays' );
		$this->assertSame( 'sig-new-0000', $store->get( $id )->credentials()['signing_key'] );

		$this->assertFalse( $store->replace_secrets( $id, [ 'signing_key' => '', 'api_token' => '' ] ), 'nothing new, nothing written' );
		$this->assertFalse( $store->replace_secrets( $id, [ 'not_a_secret' => 'x' ] ), 'a name that is not a secret field is ignored' );
	}

	/**
	 * Secrets that cannot be decrypted (the salts changed) are reported, not guessed, and a save that does not touch the secrets
	 * keeps the stored blob, so restoring the old salts brings them back.
	 *
	 * @return void
	 */
	public function test_unreadable_secrets_are_flagged_and_kept(): void {
		$id      = $this->seed();
		$blob    = $this->raw_row( $id )['secret_enc'];
		$salted  = $this->store_with( new Credential_Fields( 'cabinet_ref', [ 'api_token' ] ), new Secret_Cipher( 'other-salt' ) );
		$account = $salted->get( $id );

		$this->assertSame( '', $account->credentials()['api_token'] );
		$this->assertTrue( $account->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] );
		$this->assertSame( $blob, $account->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE_ENC ] );

		$salted->save( new Account( $id, 'fake', 'Переименован', true, $account->credentials(), null, $account->settings() ) );

		$this->assertSame( $blob, $this->raw_row( $id )['secret_enc'] );
		$this->assertSame( 'Переименован', $this->raw_row( $id )['name'] );
		$this->assertSame( self::TOKEN, $this->store->get( $id )->credentials()['api_token'] );
	}

	/**
	 * An identifier belongs to one account, and an account needs one.
	 *
	 * @return void
	 */
	public function test_an_identifier_is_unique_and_required(): void {
		$this->seed();

		try {
			$this->seed( [], true, self::IDENTIFIER, 'other', 'Копия' );
			$this->fail( 'A second account with the same identifier must be refused.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertCount( 1, $this->store->all( 'fake', false ) );
		}

		$this->expectException( \InvalidArgumentException::class );
		$this->seed( [], true, '  ', 'key', 'Пустой' );
	}

	/**
	 * Finding by the identifier.
	 *
	 * @return void
	 */
	public function test_find_by_identifier(): void {
		$id = $this->seed();

		$this->assertSame( $id, $this->store->find_by_identifier( self::IDENTIFIER )->id() );
		$this->assertNull( $this->store->find_by_identifier( 'nobody' ) );
	}

	/**
	 * A migration keeps the old id: an unknown positive id inserts with that id.
	 *
	 * @return void
	 */
	public function test_an_unknown_positive_id_inserts_with_that_id(): void {
		$id = $this->store->save( $this->account( 42, 'Из старой версии', false, 'legacy-token-0000', [], '777' ) );

		$this->assertSame( 42, $id );
		$this->assertSame( 'Из старой версии', $this->store->get( 42 )->name() );
	}

	/**
	 * The health of a check is stored only while the account still has the identifier and the secrets that were tested.
	 *
	 * @return void
	 */
	public function test_a_check_that_finishes_after_a_rotation_writes_no_health(): void {
		$id     = $this->seed();
		$tested = [
			'cabinet_ref' => self::IDENTIFIER,
			'api_token'   => self::TOKEN,
		];

		$this->store->replace_secrets( $id, [ 'api_token' => 'rotated-token-0000' ] );

		$this->assertFalse( $this->store->set_health_for_credentials( $id, $tested, new Health_Result( Health_Result::AUTH_FAILED, 'old' ) ) );
		$this->assertNull( $this->store->health( $id ), 'the rotated secrets keep «never checked»' );

		$tested['api_token'] = 'rotated-token-0000';
		$this->assertTrue( $this->store->set_health_for_credentials( $id, $tested, new Health_Result( Health_Result::OK, 'new' ) ) );
		$this->assertSame( Health_Result::OK, $this->store->health( $id )->status() );
	}

	/**
	 * The quotas the adapter read are kept for diagnostics with the identifier and time they describe, and dropped when the
	 * identifier or the secrets change.
	 *
	 * @return void
	 */
	public function test_the_quota_snapshot_belongs_to_the_credentials_it_was_read_with(): void {
		$id     = $this->seed();
		$limits = [ 'daily_update' => [ 'usage' => 3, 'limit' => 1500 ] ];
		$creds  = [
			'cabinet_ref' => self::IDENTIFIER,
			'api_token'   => self::TOKEN,
		];

		$this->store->set_health_for_credentials( $id, $creds, new Health_Result( Health_Result::OK, 'ok', null, [ 'limits' => $limits ] ) );

		$snapshot = $this->store->get( $id )->setting( 'limits_snapshot' );
		$this->assertSame( self::IDENTIFIER, $snapshot['external_id'] );
		$this->assertSame( self::NOW, $snapshot['fetched_at'] );
		$this->assertSame( $limits, $snapshot['limits'] );

		$this->store->replace_secrets( $id, [ 'api_token' => 'rotated-token-0000' ] );
		$this->assertNull( $this->store->get( $id )->setting( 'limits_snapshot' ), 'new secrets drop the old quotas' );

		$creds['api_token'] = 'rotated-token-0000';
		$this->store->set_health_for_credentials( $id, $creds, new Health_Result( Health_Result::OK, 'ok', null, [ 'limits' => $limits ] ) );
		$this->store->replace_identifier( $id, 'cabinet-9000' );

		$this->assertNull( $this->store->get( $id )->setting( 'limits_snapshot' ), 'a new identifier drops them too' );
		$this->assertNull( $this->store->health( $id ), 'and the health' );
		$this->assertSame( 'cabinet-9000', $this->store->get( $id )->credentials()['cabinet_ref'] );
	}

	/**
	 * Replacing the identifier with the one it already has changes nothing, and one that belongs to another account is refused by
	 * the database.
	 *
	 * @return void
	 */
	public function test_replace_identifier_is_a_noop_for_the_same_value_and_refused_for_a_taken_one(): void {
		$first = $this->seed();
		$this->seed( [], true, 'cabinet-2', 'k2', 'Второй' );
		$this->store->set_health( $first, new Health_Result( Health_Result::OK, 'ok' ) );

		$this->store->replace_identifier( $first, self::IDENTIFIER );
		$this->assertNotNull( $this->store->health( $first ), 'the same identifier keeps the health' );

		$this->expectException( \RuntimeException::class );
		$this->store->replace_identifier( $first, 'cabinet-2' );
	}

	/**
	 * Secrets that could not be opened get their health only for the ciphertext that failed.
	 *
	 * @return void
	 */
	public function test_unreadable_health_is_stored_only_for_the_ciphertext_that_failed(): void {
		$id   = $this->seed();
		$blob = $this->raw_row( $id )['secret_enc'];

		$this->assertFalse( $this->store->set_health_for_ciphertext( $id, $blob . 'x', new Health_Result( Health_Result::AUTH_FAILED, 'x' ) ) );
		$this->assertNull( $this->store->health( $id ) );

		$this->assertTrue( $this->store->set_health_for_ciphertext( $id, $blob, new Health_Result( Health_Result::AUTH_FAILED, 'Не удалось расшифровать' ) ) );
		$this->assertSame( Health_Result::AUTH_FAILED, $this->store->health( $id )->status() );
	}

	/**
	 * Field updates write the knobs and the name; credentials and the merchant's switch have their own writers.
	 *
	 * @return void
	 */
	public function test_field_updates_cannot_carry_credentials_or_the_switch(): void {
		$id = $this->seed();

		$this->store->update_fields(
			$id,
			[
				'name'            => '  Новое имя ',
				'stock_mode'      => 'marketplace',
				'orders_enabled'  => false,
				'warehouse_id'    => 77,
			]
		);

		$account = $this->store->get( $id );
		$this->assertSame( 'Новое имя', $account->name() );
		$this->assertSame( 'marketplace', $account->setting( 'stock_mode' ) );
		$this->assertFalse( $account->setting( 'orders_enabled' ) );
		$this->assertSame( 77, $account->setting( 'warehouse_id' ) );
		$this->assertSame( self::TOKEN, $account->credentials()['api_token'] );

		foreach ( [ 'api_token', 'secret_enc', 'external_id', 'is_enabled', 'claim_token' ] as $field ) {
			try {
				$this->store->update_fields( $id, [ $field => 'x' ] );
				$this->fail( "{$field} must not be writable here" );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertSame( 'This field has its own writer.', $exception->getMessage() );
			}
		}
	}

	/**
	 * The settings JSON is changed with a compare-and-swap: a write that lands between the read and the write is not lost.
	 *
	 * @return void
	 */
	public function test_the_settings_swap_keeps_a_concurrent_write(): void {
		$id = $this->seed( [ 'price_rule' => [ 'markup' => '10' ] ] );

		$this->db->before_query_pattern = '/^UPDATE .* SET settings = /';
		$this->db->before_query         = function () use ( $id ): void {
			$this->store->update_settings(
				$id,
				static function ( array $settings ): array {
					$settings['other_request'] = 'kept';

					return $settings;
				}
			);
		};

		$done = $this->store->update_settings(
			$id,
			static function ( array $settings ): array {
				$settings['mine'] = 'also kept';

				return $settings;
			}
		);

		$account = $this->store->get( $id );
		$this->assertTrue( $done );
		$this->assertSame( 'kept', $account->setting( 'other_request' ) );
		$this->assertSame( 'also kept', $account->setting( 'mine' ) );
		$this->assertSame( [ 'markup' => '10' ], $account->setting( 'price_rule' ) );
	}

	/**
	 * Three lost swaps in a row end the attempt instead of looping.
	 *
	 * @return void
	 */
	public function test_the_settings_swap_gives_up_after_three_lost_attempts(): void {
		$id    = $this->seed();
		$calls = 0;

		$change = function ( array $settings ) use ( &$calls, $id ): array {
			++$calls;
			// Another request changes the JSON each time this one is about to write.
			$this->db->update( Sqlite_Tables::table( 'accounts' ), [ 'settings' => '{"race":' . $calls . '}' ], [ 'account_id' => $id ] );
			$settings['mine'] = true;

			return $settings;
		};

		$this->assertFalse( $this->store->update_settings( $id, $change ) );
		$this->assertSame( 3, $calls );
		$this->assertNull( $this->store->get( $id )->setting( 'mine' ) );
	}

	/**
	 * A fence the row no longer matches stops the write before it is tried; a missing account is a plain false.
	 *
	 * @return void
	 */
	public function test_the_settings_swap_honours_its_fence(): void {
		$id     = $this->seed();
		$change = static function ( array $settings ): array {
			$settings['x'] = 1;

			return $settings;
		};

		$this->assertFalse( $this->store->update_settings( $id, $change, [ 'external_id' => 'someone-else' ] ) );
		$this->assertTrue( $this->store->update_settings( $id, $change, [ 'external_id' => self::IDENTIFIER ] ) );
		$this->assertFalse( $this->store->update_settings( 999, $change ) );
		$this->assertTrue( $this->store->update_settings( $id, $change ), 'a change that alters nothing succeeds without a write' );
	}

	/**
	 * The account row is the lease: one holder at a time, the token proves it, renewing and releasing need it.
	 *
	 * @return void
	 */
	public function test_the_account_row_can_be_claimed_by_one_holder(): void {
		$id = $this->seed();

		$token = $this->store->claim( $id, 120 );
		$this->assertIsString( $token );
		$this->assertNull( $this->store->claim( $id, 120 ), 'a second holder is refused' );
		$this->assertTrue( $this->store->holds_claim( $id, $token ) );
		$this->assertFalse( $this->store->holds_claim( $id, 'not-the-token' ) );
		$this->assertTrue( $this->store->renew_claim( $id, $token, 120 ) );

		$this->store->release_claim( $id, 'not-the-token' );
		$this->assertTrue( $this->store->holds_claim( $id, $token ), 'a caller that is not the holder changes nothing' );

		$this->store->release_claim( $id, $token );
		$this->assertNotNull( $this->store->claim( $id, 120 ) );
		$this->assertSame( Sqlite_Tables::table( 'accounts' ), $this->store->table() );
	}

	/**
	 * Builds a store over the test database.
	 *
	 * @param Credential_Fields  $fields Credential fields.
	 * @param Secret_Cipher|null $cipher Cipher; the test's own by default.
	 * @return Wpdb_Account_Store
	 */
	private function store_with( Credential_Fields $fields, ?Secret_Cipher $cipher = null ): Wpdb_Account_Store {
		return new Wpdb_Account_Store(
			$this->db,
			Sqlite_Tables::table( 'accounts' ),
			$cipher ?? $this->cipher,
			'fake',
			$fields,
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * Builds an account with one secret.
	 *
	 * @param int                 $id          Account id.
	 * @param string              $name        Name.
	 * @param bool                $is_enabled  Switch.
	 * @param string              $token       Secret.
	 * @param array<string,mixed> $settings    Settings.
	 * @param string              $identifier  Identifier.
	 * @return Account
	 */
	private function account( int $id, string $name, bool $is_enabled, string $token, array $settings = [], string $identifier = self::IDENTIFIER ): Account {
		return new Account(
			$id,
			'fake',
			$name,
			$is_enabled,
			[
				'cabinet_ref' => $identifier,
				'api_token'   => $token,
			],
			null,
			$settings
		);
	}

	/**
	 * Saves a new account.
	 *
	 * @param array<string,mixed> $settings   Settings.
	 * @param bool                $is_enabled Switch.
	 * @param string              $identifier Identifier.
	 * @param string              $token      Secret.
	 * @param string              $name       Name.
	 * @return int Account id.
	 */
	private function seed( array $settings = [], bool $is_enabled = true, string $identifier = self::IDENTIFIER, string $token = self::TOKEN, string $name = 'Основной' ): int {
		return $this->store->save( $this->account( 0, $name, $is_enabled, $token, $settings, $identifier ) );
	}

	/**
	 * Ids of accounts.
	 *
	 * @param Account[] $accounts Accounts.
	 * @return int[]
	 */
	private function ids( array $accounts ): array {
		return array_map(
			static function ( Account $account ): int {
				return $account->id();
			},
			$accounts
		);
	}

	/**
	 * Reads the raw row of an account.
	 *
	 * @param int $id Account id.
	 * @return array<string,mixed>
	 */
	private function raw_row( int $id ): array {
		$rows = $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'accounts' ) . ' WHERE account_id = ' . $id );

		return $rows[0] ?? [];
	}

	/**
	 * Statements that write a column.
	 *
	 * @param string $column Column name.
	 * @return string[]
	 */
	private function writes_to( string $column ): array {
		return array_values(
			array_filter(
				$this->db->statements,
				static function ( string $sql ) use ( $column ): bool {
					return 1 === preg_match( '/^\s*(UPDATE|INSERT)/i', $sql ) && false !== strpos( $sql, $column );
				}
			)
		);
	}
}
