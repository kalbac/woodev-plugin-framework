<?php
/**
 * The import result and card state, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Card_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Card_Outcome;
use Woodev\Framework\Marketplace\Flows\Import_Outcome;

/**
 * Names cards by `card_ref`; `model_ref` reaches a card after it exists. What the marketplace reports is whatever the test put in
 * {@see self::$import} and {@see self::$cards}; a `\Throwable` in {@see self::$failure} is thrown by the next read.
 */
final class Scripted_Card_Lifecycle implements Card_Lifecycle {
	/**
	 * Import items the marketplace reports, by article.
	 *
	 * @var array<string,Import_Outcome>
	 */
	public $import = [];

	/**
	 * Cards the marketplace reports, by card reference.
	 *
	 * @var array<string,Card_Outcome>
	 */
	public $cards = [];

	/**
	 * Thrown by the next read when set.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Runs on every read before it answers (a test can take the account claim away there).
	 *
	 * @var callable|null
	 */
	public $on_read;

	/**
	 * Reads: `[ 'import', task number ]` or `[ 'cards', card references ]`.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	public $reads = [];

	/**
	 * Returns the name of the card identifier.
	 *
	 * @return string
	 */
	public function card_id_name(): string {
		return 'card_ref';
	}

	/**
	 * Returns the identifiers that arrive late.
	 *
	 * @return string[]
	 */
	public function late_id_names(): array {
		return [ 'model_ref' ];
	}

	/**
	 * Reads the import result.
	 *
	 * @param Account $account        Account.
	 * @param string  $remote_task_id Task number.
	 * @param string  $context        Job.
	 * @return array<string,Import_Outcome>
	 * @throws \Throwable The scripted failure.
	 */
	public function read_import( Account $account, string $remote_task_id, string $context ): array {
		$this->reads[] = [ 'import', $remote_task_id ];
		$this->before_answer();

		return $this->import;
	}

	/**
	 * Reads the cards.
	 *
	 * @param Account $account Account.
	 * @param array   $links   Links by card reference.
	 * @param string  $context Job.
	 * @return array<string,Card_Outcome>
	 * @throws \Throwable The scripted failure.
	 */
	public function read_cards( Account $account, array $links, string $context ): array {
		$this->reads[] = [ 'cards', array_map( 'strval', array_keys( $links ) ) ];
		$this->before_answer();

		return array_intersect_key( $this->cards, $links );
	}

	/**
	 * Runs the hook and throws the scripted failure once.
	 *
	 * @return void
	 * @throws \Throwable The scripted failure.
	 */
	private function before_answer(): void {
		if ( null !== $this->on_read ) {
			call_user_func( $this->on_read );
		}

		if ( null !== $this->failure ) {
			$failure       = $this->failure;
			$this->failure = null;

			throw $failure;
		}
	}
}
