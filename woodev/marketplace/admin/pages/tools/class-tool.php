<?php
/**
 * One PHP-defined tool of the «Инструменты» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Shipping\Settings\Tool_Result;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace tool: a merchant action on one account, written in PHP.
 *
 * The page shows the title and description, a preview sentence for the chosen account, and a confirm button. Nothing
 * runs until the merchant confirms. A tool that takes the account claim (`$takes_claim`) is disabled while another run
 * holds it, and the runner takes the claim itself before the callback runs.
 *
 * The browser never receives the callbacks: {@see self::describe()} returns the presentation only.
 *
 * @since 2.1.5
 */
final class Tool {
	/**
	 * Tool id, unique within the page. A route segment: lower-case letters, digits, dashes and underscores.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Title of the tool.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * What the tool does, shown under the title.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Label of the confirm button.
	 *
	 * @var string
	 */
	private $button;

	/**
	 * Whether the tool takes the account claim and must not run beside another run.
	 *
	 * @var bool
	 */
	private $takes_claim;

	/**
	 * Preview sentence for an account (`function ( int $account_id ): string`). Read-only.
	 *
	 * @var callable
	 */
	private $plan;

	/**
	 * Runs the tool for an account (`function ( int $account_id ): Tool_Result`).
	 *
	 * @var callable
	 */
	private $run;

	/**
	 * Creates a tool.
	 *
	 * @since 2.1.5
	 *
	 * @param string   $id          Tool id.
	 * @param string   $title       Title.
	 * @param string   $description What the tool does.
	 * @param string   $button      Confirm button label.
	 * @param callable $plan        `function ( int $account_id ): string` — the preview sentence; it must change nothing.
	 * @param callable $run         `function ( int $account_id ): Tool_Result` — the work.
	 * @param bool     $takes_claim Whether the tool takes the account claim.
	 * @return self
	 */
	public static function create( string $id, string $title, string $description, string $button, callable $plan, callable $run, bool $takes_claim = false ): self {
		$tool = new self();

		$tool->id          = $id;
		$tool->title       = $title;
		$tool->description = $description;
		$tool->button      = $button;
		$tool->plan        = $plan;
		$tool->run         = $run;
		$tool->takes_claim = $takes_claim;

		return $tool;
	}

	/**
	 * Tool id.
	 *
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Whether the tool takes the account claim.
	 *
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function takes_claim(): bool {
		return $this->takes_claim;
	}

	/**
	 * The preview sentence for an account.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account the merchant chose.
	 * @return string
	 */
	public function summary( int $account_id ): string {
		return (string) call_user_func( $this->plan, $account_id );
	}

	/**
	 * Runs the tool for an account. The caller has already checked the account and the claim.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account the merchant chose.
	 * @return Tool_Result
	 */
	public function run( int $account_id ): Tool_Result {
		return call_user_func( $this->run, $account_id );
	}

	/**
	 * The presentation of the tool for the browser. The callbacks are never included.
	 *
	 * @since 2.1.5
	 *
	 * @return array{id:string,title:string,description:string,button:string,takes_claim:bool}
	 */
	public function describe(): array {
		return [
			'id'          => $this->id,
			'title'       => $this->title,
			'description' => $this->description,
			'button'      => $this->button,
			'takes_claim' => $this->takes_claim,
		];
	}
}
