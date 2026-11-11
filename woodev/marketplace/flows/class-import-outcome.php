<?php
/**
 * What the marketplace said about one imported item.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's verdict on one item of an import task, already translated into the framework's vocabulary by the adapter:
 * whether it is final, the link state and the ledger status it leads to, the error to keep and the identifiers it brought.
 *
 * @since 2.0.30
 */
final class Import_Outcome {
	/**
	 * Whether the verdict is final.
	 *
	 * @var bool
	 */
	private $final;

	/**
	 * Link state the item leads to (`Link_Record::STATE_*`).
	 *
	 * @var string
	 */
	private $link_state;

	/**
	 * Ledger status of the item (`Task_Ledger::ITEM_*`).
	 *
	 * @var string
	 */
	private $item_status;

	/**
	 * Error code kept on the link.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Error text kept on the link.
	 *
	 * @var string
	 */
	private $error_text;

	/**
	 * Errors kept on the ledger item: `{code, message}` each.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $errors;

	/**
	 * Identifiers the item brought (name => id).
	 *
	 * @var array<string,int|string>
	 */
	private $remote_ids;

	/**
	 * Creates the outcome.
	 *
	 * @since 2.0.30
	 *
	 * @param bool                            $is_final    Whether the verdict is final.
	 * @param string                          $link_state  Link state the item leads to.
	 * @param string                          $item_status Ledger status of the item.
	 * @param string                          $error_code  Error code, '' for none.
	 * @param string                          $error_text  Error text, '' for none.
	 * @param array<int,array<string,string>> $errors      Errors of the ledger item.
	 * @param array<string,int|string>        $remote_ids  Identifiers the item brought.
	 */
	public function __construct( bool $is_final, string $link_state, string $item_status, string $error_code = '', string $error_text = '', array $errors = [], array $remote_ids = [] ) {
		$this->final       = $is_final;
		$this->link_state  = $link_state;
		$this->item_status = $item_status;
		$this->error_code  = $error_code;
		$this->error_text  = $error_text;
		$this->errors      = $errors;
		$this->remote_ids  = $remote_ids;
	}

	/**
	 * Tells whether the verdict is final.
	 *
	 * @since 2.0.30
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return $this->final;
	}

	/**
	 * Returns the link state the item leads to.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function link_state(): string {
		return $this->link_state;
	}

	/**
	 * Returns the ledger status of the item.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function item_status(): string {
		return $this->item_status;
	}

	/**
	 * Returns the error code, '' for none.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Returns the error text, '' for none.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function error_text(): string {
		return $this->error_text;
	}

	/**
	 * Returns the errors of the ledger item.
	 *
	 * @since 2.0.30
	 *
	 * @return array<int,array<string,string>>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns the identifiers the item brought.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,int|string>
	 */
	public function remote_ids(): array {
		return $this->remote_ids;
	}
}
