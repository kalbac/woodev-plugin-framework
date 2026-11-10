<?php
/**
 * The small values of the persistence layer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;

/**
 * Credential fields, the claim fence, the link record and the persistence exception.
 */
final class DataValuesTest extends TestCase {
	/**
	 * The adapter declares one identifier and its secrets, in order.
	 *
	 * @return void
	 */
	public function test_credential_fields_expose_the_declaration(): void {
		$fields = new Credential_Fields( 'client_ref', [ 'token', 'signing_key' ] );

		$this->assertSame( 'client_ref', $fields->identifier() );
		$this->assertSame( [ 'token', 'signing_key' ], $fields->secrets() );
		$this->assertSame(
			[
				'token'       => 'a',
				'signing_key' => '',
			],
			$fields->secrets_of(
				[
					'signing_key' => '',
					'token'       => 'a',
					'client_ref'  => 'not a secret',
				]
			),
			'only the secrets, in the declared order, a missing one as empty'
		);
	}

	/**
	 * A declaration without a secret, with an empty name or with a repeated name cannot work.
	 *
	 * @dataProvider invalid_declarations
	 *
	 * @param string   $identifier Identifier.
	 * @param string[] $secrets    Secrets.
	 * @return void
	 */
	public function test_an_unusable_declaration_is_refused( string $identifier, array $secrets ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Credential_Fields( $identifier, $secrets );
	}

	/**
	 * Declarations that must be refused.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function invalid_declarations(): array {
		return [
			'no secret'            => [ 'id', [] ],
			'empty identifier'     => [ '', [ 'token' ] ],
			'empty secret name'    => [ 'id', [ '' ] ],
			'secret named like id' => [ 'id', [ 'id' ] ],
			'repeated secret'      => [ 'id', [ 'token', 'token' ] ],
		];
	}

	/**
	 * The fence is the accounts table and the token, nothing more.
	 *
	 * @return void
	 */
	public function test_the_claim_fence_is_a_plain_value(): void {
		$fence = new Claim_Fence( 'wp_woodev_fake_accounts', 'abc' );

		$this->assertSame( 'wp_woodev_fake_accounts', $fence->table() );
		$this->assertSame( 'abc', $fence->token() );
	}

	/**
	 * A link record normalises what the database hands back: an empty article is none, a zero warehouse is none, JSON columns
	 * decode, and an unknown kind is an offer.
	 *
	 * @return void
	 */
	public function test_a_link_record_normalises_a_database_row(): void {
		$record = Link_Record::from_row(
			[
				'link_id'             => '5',
				'account_id'          => '7',
				'wc_product_id'       => '101',
				'kind'                => 'weird',
				'offer_id'            => '',
				'warehouse_id'        => '0',
				'price_rule_override' => '{"price":{"kind":"percent","value":5}}',
				'attribute_overrides' => '',
				'mapping_id'          => '3',
			]
		);

		$this->assertSame( 5, $record->link_id() );
		$this->assertTrue( $record->is_offer() );
		$this->assertNull( $record->offer_id() );
		$this->assertNull( $record->warehouse_id() );
		$this->assertSame( 5, $record->price_rule_override()['price']['value'] );
		$this->assertNull( $record->attribute_overrides() );
		$this->assertSame( 3, $record->mapping_id() );
	}

	/**
	 * The article is frozen once the marketplace knows the card or a submit carried it; before that it is not.
	 *
	 * @return void
	 */
	public function test_the_article_freezes_with_the_first_send(): void {
		$this->assertNull( Link_Record::offer( 7, 1, 0, 'A', [] )->frozen_offer_id() );
		$this->assertNull( Link_Record::offer( 7, 1, 0, 'A', [ 'card_state' => 'queued' ] )->frozen_offer_id() );
		$this->assertSame( 'A', Link_Record::offer( 7, 1, 0, 'A', [ 'card_state' => 'submitted' ] )->frozen_offer_id() );
		$this->assertSame( 'A', Link_Record::offer( 7, 1, 0, 'A', [ 'remote_product_id' => 5 ] )->frozen_offer_id() );
	}

	/**
	 * The database form of a record leaves out the id and encodes the overrides.
	 *
	 * @return void
	 */
	public function test_a_link_record_goes_back_to_columns(): void {
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$columns = Link_Record::offer( 7, 1, 0, 'A', [ 'price_rule_override' => [ 'price' => [ 'kind' => 'fixed', 'value' => '9' ] ] ] )->with( [ 'sync_stock' => 1 ] )->to_columns();

		\Brain\Monkey\tearDown();

		$this->assertArrayNotHasKey( 'link_id', $columns );
		$this->assertSame( 1, $columns['sync_stock'] );
		$this->assertSame( '{"price":{"kind":"fixed","value":"9"}}', $columns['price_rule_override'] );
		$this->assertNull( $columns['attribute_overrides'] );
		$this->assertSame( [ 'remote_product_id', 'remote_sku', 'remote_model_id' ], array_values( array_intersect( [ 'remote_product_id', 'remote_sku', 'remote_model_id' ], array_keys( $columns ) ) ) );
	}

	/**
	 * The exception names what failed and the database's own text.
	 *
	 * @return void
	 */
	public function test_the_persistence_exception_names_what_failed(): void {
		$this->assertSame( 'Could not write a label task.', Persistence_Exception::database( 'a label task' )->getMessage() );
		$this->assertSame( 'Could not write a label task: Duplicate entry', Persistence_Exception::database( 'a label task', 'Duplicate entry' )->getMessage() );
		$this->assertSame( 'Could not queue a poll.', Persistence_Exception::queue( 'a poll' )->getMessage() );
		$this->assertInstanceOf( \RuntimeException::class, Persistence_Exception::queue( 'x' ) );
	}
}
