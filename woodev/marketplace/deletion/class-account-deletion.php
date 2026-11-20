<?php
/**
 * The merchant's request to delete an account: the state to show, and the start.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The rules, in one place, for the controller and the screen:
 *
 * 1. only an account of this plugin's marketplace can be deleted (anything else is «not found»);
 * 2. the merchant types the account's identifier (Client-Id) exactly as it is stored (400 otherwise);
 * 3. an account that is still switched on is switched off first: the dialog says so before the merchant confirms, and the
 *    job refuses an enabled account, so the switch goes off before the job is queued (and comes back on if the queue refuses);
 * 4. then the deletion is queued as a background job ({@see Account_Deletion_Job}); a deletion already queued or running is
 *    not queued twice.
 *
 * The capability and the nonce are the controller's. The job checks the switch again, because the merchant can switch the account
 * on between the request and the run.
 *
 * @since 2.1.5
 */
final class Account_Deletion {
	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Adapter id: an account of another marketplace is not this plugin's.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Name of the credential that identifies the account (Client-Id).
	 *
	 * @var string
	 */
	private $identifier_field;

	/**
	 * Preview.
	 *
	 * @var Account_Deletion_Preview
	 */
	private $preview;

	/**
	 * Progress.
	 *
	 * @var Account_Deletion_Progress
	 */
	private $progress;

	/**
	 * Scheduler.
	 *
	 * @var Account_Deletion_Scheduler
	 */
	private $scheduler;

	/**
	 * Creates the service.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Account_Store         $accounts         Accounts.
	 * @param string                     $adapter_id       Adapter id.
	 * @param string                     $identifier_field Name of the identifying credential.
	 * @param Account_Deletion_Preview   $preview          Preview.
	 * @param Account_Deletion_Progress  $progress         Progress.
	 * @param Account_Deletion_Scheduler $scheduler        Scheduler.
	 */
	public function __construct( Wpdb_Account_Store $accounts, string $adapter_id, string $identifier_field, Account_Deletion_Preview $preview, Account_Deletion_Progress $progress, Account_Deletion_Scheduler $scheduler ) {
		$this->accounts         = $accounts;
		$this->adapter_id       = $adapter_id;
		$this->identifier_field = $identifier_field;
		$this->preview          = $preview;
		$this->progress         = $progress;
		$this->scheduler        = $scheduler;
	}

	/**
	 * What the screen shows for an account: what would go (and, for an enabled one, that it is switched off first), and how far a deletion has got.
	 *
	 * A deleted account answers from the result the job left (for a day), so the screen that was waiting for the end can say it.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return array{deleted:bool,can_delete:bool,preview:array<string,mixed>|null,deletion:array<string,mixed>|null}
	 * @throws Account_Deletion_Refused With 404 when there is no such account and no result to show.
	 */
	public function state( int $account_id ): array {
		$account  = $this->account( $account_id );
		$deletion = $this->progress->get( $account_id );

		if ( null === $account ) {
			if ( null === $deletion ) {
				throw self::not_found();
			}

			return $this->answer( true, null, $deletion );
		}

		return $this->answer( false, $this->preview->describe( $account ), $deletion );
	}

	/**
	 * Starts the deletion of an account.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id   Account id.
	 * @param string $confirmation What the merchant typed as the account's identifier.
	 * @return array{deleted:bool,can_delete:bool,preview:array<string,mixed>|null,deletion:array<string,mixed>|null}
	 * @throws Account_Deletion_Refused When the account is unknown (404), the confirmation does not match (400),
	 *                                  or the job could not be queued (500).
	 */
	public function start( int $account_id, string $confirmation ): array {
		$account = $this->account( $account_id );

		if ( null === $account ) {
			throw self::not_found();
		}

		if ( trim( $confirmation ) !== $this->identifier_of( $account ) || '' === trim( $confirmation ) ) {
			throw new Account_Deletion_Refused( __( 'Идентификатор введён неверно. Введите его в точности как на карточке аккаунта.', 'woodev-plugin-framework' ), 400 );
		}

		$was_enabled = $account->is_enabled();

		if ( $was_enabled ) {
			$this->accounts->set_enabled( $account_id, false );
		}

		$this->progress->begin( $account_id );

		if ( ! $this->scheduler->queue( $account_id ) ) {
			// No job exists, so nothing is being deleted: an account that was working goes on working.
			if ( $was_enabled ) {
				$this->accounts->set_enabled( $account_id, true );
			}

			$this->progress->fail( $account_id, __( 'Не удалось поставить удаление в очередь. Проверьте, что Action Scheduler работает, и повторите.', 'woodev-plugin-framework' ) );

			throw new Account_Deletion_Refused( __( 'Не удалось поставить удаление в очередь. Повторите позже.', 'woodev-plugin-framework' ), 500 );
		}

		return $this->state( $account_id );
	}

	/**
	 * Builds the answer.
	 *
	 * @param bool                     $deleted  Whether the account is gone.
	 * @param array<string,mixed>|null $preview  The preview, while the account exists.
	 * @param array<string,mixed>|null $deletion The progress, when a deletion was started.
	 * @return array{deleted:bool,can_delete:bool,preview:array<string,mixed>|null,deletion:array<string,mixed>|null}
	 */
	private function answer( bool $deleted, ?array $preview, ?array $deletion ): array {
		return [
			'deleted'    => $deleted,
			'can_delete' => ! $deleted,
			'preview'    => $preview,
			'deletion'   => $deletion,
		];
	}

	/**
	 * The account of this plugin's marketplace with that id.
	 *
	 * @param int $account_id Account id.
	 * @return Account|null
	 */
	private function account( int $account_id ): ?Account {
		$account = $this->accounts->get( $account_id );

		return null !== $account && $account->adapter_id() === $this->adapter_id ? $account : null;
	}

	/**
	 * The identifier credential of an account.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	private function identifier_of( Account $account ): string {
		return trim( (string) ( $account->credentials()[ $this->identifier_field ] ?? '' ) );
	}

	/**
	 * The refusal for an unknown account.
	 *
	 * @return Account_Deletion_Refused
	 */
	private static function not_found(): Account_Deletion_Refused {
		return new Account_Deletion_Refused( __( 'Аккаунт не найден. Обновите страницу.', 'woodev-plugin-framework' ), 404 );
	}
}
