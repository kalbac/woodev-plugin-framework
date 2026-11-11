<?php
/**
 * Base of the label flow tests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Label_Provider;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The label store over the test database and the clock, the fake marketplace's label provider, and the service and jobs built on
 * the fake adapter's answers (the error classifier and the provider are the adapter's).
 */
abstract class Label_Test_Case extends Sync_Test_Case {
	protected const PDF = '%PDF-1.4 label of 0001-1 (recipient: Иван, Москва)';

	/**
	 * Store.
	 *
	 * @var Wpdb_Label_Store
	 */
	protected $labels;

	/**
	 * The marketplace's label calls.
	 *
	 * @var Fake_Label_Provider
	 */
	protected $provider;

	/**
	 * The fake adapter the flows are built from.
	 *
	 * @var Fake_Marketplace_Adapter
	 */
	protected $adapter;

	/**
	 * Builds the store and the adapter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);

		$this->labels   = new Wpdb_Label_Store( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'documents' ), $this->clock() );
		$this->provider = new Fake_Label_Provider();
		$this->adapter  = new Fake_Marketplace_Adapter(
			null,
			[
				'error_classifier' => $this->classifier,
				'label_provider'   => $this->provider,
			]
		);
	}

	/**
	 * The licence gate as the test set it.
	 *
	 * @return License_Gate
	 */
	protected function license(): License_Gate {
		return new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);
	}

	/**
	 * Builds the service over the adapter's provider.
	 *
	 * @return Label_Service
	 */
	protected function service(): Label_Service {
		return new Label_Service( $this->adapter->label_provider(), $this->labels, $this->license(), $this->adapter->error_classifier(), $this->names() );
	}

	/**
	 * Builds the jobs.
	 *
	 * @return Label_Jobs
	 */
	protected function jobs(): Label_Jobs {
		return new Label_Jobs( $this->job_gate(), $this->service(), $this->labels, $this->adapter->error_classifier(), $this->names() );
	}

	/**
	 * Stores a submitted label task of the account.
	 *
	 * @param string[] $keys Remote-order keys.
	 * @return int Local task id.
	 */
	protected function task( array $keys ): int {
		return $this->labels->add_task( $this->account_id, '777', $keys );
	}

	/**
	 * Stores a document of the account.
	 *
	 * @param string[] $keys Remote-order keys.
	 * @return int Document id.
	 */
	protected function document( array $keys ): int {
		return $this->labels->add_document( $this->account_id, 1, $keys, 'application/pdf', self::PDF );
	}

	/**
	 * The file answer of the marketplace.
	 *
	 * @return Label_Answer
	 */
	protected function file_answer(): Label_Answer {
		return Label_Answer::file( self::PDF, 'application/pdf', [ 'result' => [ 'status' => 'completed' ] ] );
	}

	/**
	 * The account.
	 *
	 * @return Account
	 */
	protected function account(): Account {
		return $this->account_now();
	}
}
