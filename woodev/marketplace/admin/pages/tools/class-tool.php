<?php
/**
 * One PHP-defined tool of the «Инструменты» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Shipping\Settings\Tool_Result;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace tool: a merchant action on one account, written in PHP.
 *
 * The page shows the title and description, a preview sentence for the chosen account, and a confirm button. Nothing
 * runs until the merchant confirms.
 *
 * Three declarations decide when the tool may run:
 *
 * - `$remote` (default true): the tool calls the marketplace. A remote tool is a NEW remote action, so it is disabled while
 *   the licence is not in force or the account is paused, as the background runs are. The page does not check the plugin's
 *   write gate (`Marketplace_Plugin::account_write_gate()`): a tool that writes checks that gate itself. Only a tool that
 *   touches local data alone (a migration, a cache clean) says `false`.
 * - `$takes_claim`: the tool must not run beside a background run. It is disabled while another run holds the account
 *   claim, and the runner takes the claim itself before the callback runs. The callback then receives the claim fence for
 *   the fenced stores and a renew callable that extends the claim (it lasts a couple of minutes; a long tool calls it).
 * - `$scope`: {@see self::SCOPE_ACCOUNT} (default) tools work on the account the merchant chose; {@see self::SCOPE_SITE}
 *   tools work on the whole site, need no account (so they run before any account exists), receive account id 0 and
 *   never take a claim.
 *
 * The browser never receives the callbacks: {@see self::describe()} returns the presentation only.
 *
 * @since 2.1.5
 */
final class Tool {
	/**
	 * The tool works on the account the merchant chose.
	 */
	public const SCOPE_ACCOUNT = 'account';

	/**
	 * The tool works on the whole site and needs no account.
	 */
	public const SCOPE_SITE = 'site';

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
	 * Whether the tool calls the marketplace and so waits for the licence and the account pause.
	 *
	 * @var bool
	 */
	private $remote;

	/**
	 * {@see self::SCOPE_ACCOUNT} or {@see self::SCOPE_SITE}.
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Preview sentence for an account (`function ( int $account_id ): string`). Read-only.
	 *
	 * @var callable
	 */
	private $plan;

	/**
	 * Runs the tool (`function ( int $account_id, ?Claim_Fence $fence, ?callable $renew ): Tool_Result`).
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
	 *                              A site tool gets account id 0.
	 * @param callable $run         `function ( int $account_id, ?Claim_Fence $fence, ?callable $renew ): Tool_Result` — the
	 *                              work. `$fence` and `$renew` (`function (): bool`, false when the claim was lost) are null
	 *                              unless the tool takes the claim; a callback may declare fewer parameters.
	 * @param bool     $takes_claim Whether the tool takes the account claim. Ignored for a site tool.
	 * @param bool     $remote      Whether the tool calls the marketplace. True unless the tool says otherwise, so a tool
	 *                              that forgets the flag stays behind the licence gate.
	 * @param string   $scope       {@see self::SCOPE_ACCOUNT} or {@see self::SCOPE_SITE}; anything else is an account tool.
	 * @return self
	 */
	public static function create( string $id, string $title, string $description, string $button, callable $plan, callable $run, bool $takes_claim = false, bool $remote = true, string $scope = self::SCOPE_ACCOUNT ): self {
		$tool = new self();

		$tool->id          = $id;
		$tool->title       = $title;
		$tool->description = $description;
		$tool->button      = $button;
		$tool->plan        = $plan;
		$tool->run         = $run;
		$tool->scope       = self::SCOPE_SITE === $scope ? self::SCOPE_SITE : self::SCOPE_ACCOUNT;
		$tool->takes_claim = $takes_claim && self::SCOPE_ACCOUNT === $tool->scope;
		$tool->remote      = $remote;

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
	 * Whether the tool calls the marketplace, and so waits for the licence and the account pause.
	 *
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function is_remote(): bool {
		return $this->remote;
	}

	/**
	 * Whether the tool works on the whole site and needs no account.
	 *
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function is_site_wide(): bool {
		return self::SCOPE_SITE === $this->scope;
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
	 * Runs the tool. The caller has already checked the account, the gates and the claim.
	 *
	 * @since 2.1.5
	 *
	 * @param int              $account_id Account the merchant chose; 0 for a site tool.
	 * @param Claim_Fence|null $fence      The account claim the runner took, or null when the tool takes none.
	 * @param callable|null    $renew      Extends the claim: `function (): bool`, false when it was lost; null with no claim.
	 * @return Tool_Result
	 */
	public function run( int $account_id, ?Claim_Fence $fence = null, ?callable $renew = null ): Tool_Result {
		return call_user_func( $this->run, $account_id, $fence, $renew );
	}

	/**
	 * The presentation of the tool for the browser. The callbacks are never included.
	 *
	 * @since 2.1.5
	 *
	 * @return array{id:string,title:string,description:string,button:string,takes_claim:bool,scope:string}
	 */
	public function describe(): array {
		return [
			'id'          => $this->id,
			'title'       => $this->title,
			'description' => $this->description,
			'button'      => $this->button,
			'takes_claim' => $this->takes_claim,
			'scope'       => $this->scope,
		];
	}
}
