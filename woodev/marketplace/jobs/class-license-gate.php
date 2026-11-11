<?php
/**
 * The licence gate of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The one door every NEW remote action goes through: export, stock and price pushes, import, order refresh, labels,
 * notification registration, catalogue refresh. It asks the framework licence engine whether the server-signed licence is in
 * force and, when it is not, gives the sentence the merchant reads.
 *
 * What is never gated: the notification intake, the settings pages and the connection test, diagnostics, viewing local data.
 * A caller that belongs to one of those simply does not ask the gate. There is no switch that turns the gate off.
 *
 * The sentence is the one a carrier plugin shows ({@see \Woodev\Framework\Shipping\License_Gate::refusal()}), word for word, so
 * a merchant reads the same thing whichever Woodev plugin refused; the marketplace layer does not load the shipping layer, so it
 * is written here again.
 *
 * @since 2.0.30
 */
final class License_Gate {
	/**
	 * Slug of the licence page of the Woodev admin menu.
	 */
	public const LICENSE_PAGE = 'woodev-licenses';

	/**
	 * Answers whether the licence is in force: `function (): bool`.
	 *
	 * @var callable
	 */
	private $in_force;

	/**
	 * Creates the gate.
	 *
	 * @since 2.0.30
	 *
	 * @param callable $in_force Answers whether the licence is in force: `function (): bool`.
	 */
	public function __construct( callable $in_force ) {
		$this->in_force = $in_force;
	}

	/**
	 * Builds the gate over the framework licence engine of a plugin.
	 *
	 * A plugin with no store product has nothing to license and is never gated. An engine that throws means «not in force»: a
	 * broken licence check must not let a remote action through.
	 *
	 * @since 2.0.30
	 *
	 * @param \Woodev_Plugin $plugin Plugin.
	 * @return self
	 */
	public static function for_plugin( \Woodev_Plugin $plugin ): self {
		return new self(
			static function () use ( $plugin ): bool {
				try {
					if ( ! \Woodev_Plugins_License::has_store_product( $plugin->get_download_id() ) ) {
						return true;
					}

					return $plugin->get_license_instance()->is_license_in_force();
				} catch ( \Throwable $e ) {
					return false;
				}
			}
		);
	}

	/**
	 * Tells whether the licence is in force.
	 *
	 * @since 2.0.30
	 *
	 * @return bool
	 */
	public function is_in_force(): bool {
		return true === call_user_func( $this->in_force );
	}

	/**
	 * Returns the refusal for a new remote action, or null when the action may go on.
	 *
	 * @since 2.0.30
	 *
	 * @param string $action What cannot be done, as an infinitive phrase («отправить остатки»).
	 * @return string|null
	 */
	public function refusal_for( string $action ): ?string {
		return $this->is_in_force() ? null : self::refusal( $action );
	}

	/**
	 * The sentence a merchant reads when a new remote action is not allowed. Plain text with the address written out.
	 *
	 * @since 2.0.30
	 *
	 * @param string $action What cannot be done, as an infinitive phrase.
	 * @return string
	 */
	public static function refusal( string $action ): string {
		return sprintf(
			/* translators: 1: what the merchant tried to do, 2: the licence page address */
			__( 'Лицензия плагина не действует, поэтому нельзя %1$s. Введите или продлите ключ на странице лицензий: %2$s', 'woodev-plugin-framework' ),
			$action,
			self::page_url()
		);
	}

	/**
	 * The licence page address. Built with `admin_url()`, not `menu_page_url()`, which answers '' in a REST or cron request.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::LICENSE_PAGE );
	}
}
