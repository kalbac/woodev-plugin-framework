<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Plugin;
use Woodev_Account_Connection;
use InvalidArgumentException;

/**
 * Platform-neutral competitor-notification engine.
 *
 * A plugin extends this and implements get_competitor_rules(). On each admin
 * screen load run() normalizes every raw rule to a Competitor_Rule, detects
 * whether any competitor slug is active, suppresses recommend rules when our
 * equivalent is installed, then asks the resolved renderer to create/update or
 * delete the note. The engine emits a renderer-agnostic note payload (neutral
 * 'type' strings); the WC-specific gate (class_exists( Note::class ) — the
 * gotcha-correct one, NOT is_enhanced_admin_available()) lives inside the
 * renderer layer, so this engine carries no WooCommerce reference.
 *
 * @since 2.0.2
 */
abstract class Competitor_Notification_Handler {

	/** @since 2.0.2 neutral note type → WC E_WC_ADMIN_NOTE_UPDATE in the WC renderer */
	public const TYPE_UPDATE = 'update';

	/** @since 2.0.2 neutral note type → WC E_WC_ADMIN_NOTE_ERROR in the WC renderer */
	public const TYPE_ERROR = 'error';

	/** @var Woodev_Plugin owning plugin */
	private Woodev_Plugin $plugin;

	/** @var Competitor_Notice_Renderer|null lazily-resolved renderer */
	private ?Competitor_Notice_Renderer $renderer = null;

	/**
	 * @since 2.0.2
	 *
	 * @param Woodev_Plugin $plugin owning plugin
	 */
	public function __construct( Woodev_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Per-plugin competitor rules. Each entry is a plain array consumed by
	 * Competitor_Rule. The competitor→product mapping lives here, never in the
	 * framework.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function get_competitor_rules(): array;

	/**
	 * Runs detection + rendering for every rule. Safe to call on every admin
	 * screen load. Malformed rules are skipped (never fatal an admin page).
	 *
	 * @since 2.0.2
	 */
	public function run(): void {

		$renderer = $this->get_renderer();

		foreach ( $this->get_competitor_rules() as $raw ) {

			try {
				$rule = new Competitor_Rule( $raw );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}

			$active_slug = $this->get_active_slug( $rule );

			if ( null === $active_slug || $this->is_suppressed( $rule ) ) {
				$renderer->delete( $rule );
				continue;
			}

			$renderer->render( $rule, $this->build_note( $rule, $active_slug ) );
		}
	}

	/**
	 * The FIRST of the rule's detect slugs that is an active plugin, or null
	 * when none is active. Used both for detection (null ⇒ not present) and to
	 * target the conflict deactivate link at the actually-active competitor (not
	 * blindly the first declared slug).
	 *
	 * @since 2.0.2
	 */
	protected function get_active_slug( Competitor_Rule $rule ): ?string {

		foreach ( $rule->get_detect_slugs() as $slug ) {
			if ( $this->plugin->is_plugin_active( $slug ) ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Recommend rules are suppressed when our equivalent plugin is active. When
	 * our_plugin_file is omitted the rule degrades (never suppressed).
	 *
	 * @since 2.0.2
	 */
	protected function is_suppressed( Competitor_Rule $rule ): bool {

		if ( ! $rule->is_recommend() ) {
			return false;
		}

		$our_file = $rule->get_our_plugin_file();

		return null !== $our_file && $this->plugin->is_plugin_active( $our_file );
	}

	/**
	 * Builds the renderer-agnostic note payload (title, content, type, image,
	 * actions), applying per-rule overrides over the per-mode default template.
	 *
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule        the active rule
	 * @param string          $active_slug the detect slug found active (conflict deactivate target)
	 *
	 * @return array<string,mixed>
	 */
	protected function build_note( Competitor_Rule $rule, string $active_slug ): array {

		if ( $rule->is_recommend() ) {

			$title   = $rule->get_title_override() ?? 'Информация от Woodev: альтернативный плагин';
			$content = $rule->get_content_override() ?? $this->default_recommend_content( $rule );

			$actions = [
				[
					'name'    => 'plugin-details',
					'label'   => 'Перейти на страницу плагина',
					'url'     => $this->get_recommend_action_url( $rule ),
					'primary' => true,
				],
				$this->dismiss_action(),
			];

			$type = self::TYPE_UPDATE;

		} else {

			$title   = $rule->get_title_override() ?? 'Информация от Woodev: обнаружен сторонний плагин доставки';
			$content = $rule->get_content_override() ?? $this->default_conflict_content( $rule );

			$actions = [
				[
					'name'    => 'deactivate-plugin',
					'label'   => sprintf( 'Отключить плагин %s', $this->competitor_label( $rule ) ),
					'url'     => $this->get_deactivation_url( $active_slug ),
					'primary' => true,
				],
				$this->dismiss_action(),
			];

			$type = self::TYPE_ERROR;
		}

		return [
			'title'   => $title,
			'content' => $content,
			'type'    => $type,
			'image'   => (string) ( $rule->get_image_override() ?? '' ),
			'actions' => $actions,
		];
	}

	/**
	 * Default recommend template (mirrors v1 get_recommendation_notice_content).
	 *
	 * @since 2.0.2
	 */
	protected function default_recommend_content( Competitor_Rule $rule ): string {
		return sprintf(
			'Мы обнаружили, что на вашем сайте используется плагин <strong>%s</strong>. Хотим предложить вам альтернативу — <strong>%s</strong> от Woodev.<br />Наш плагин стабилен, активно поддерживается, совместим с другими нашими решениями и регулярно обновляется.',
			$this->competitor_label( $rule ),
			'' !== $rule->get_our_name() ? $rule->get_our_name() : 'наш плагин'
		);
	}

	/**
	 * Default conflict template (mirrors v1 get_competitor_notice_content).
	 *
	 * @since 2.0.2
	 */
	protected function default_conflict_content( Competitor_Rule $rule ): string {
		return sprintf(
			'На вашем сайте активен плагин <strong>%s</strong>. В некоторых случаях плагины с похожей функциональностью могут конфликтовать: например, добавлять дублирующиеся методы доставки, влиять на расчёт стоимости или мешать оформлению заказов.<br />Если у вас всё работает корректно — ничего делать не нужно. Если возникнут вопросы — обращайтесь в <a href="https://woodev.ru/support">нашу поддержку</a>.',
			$this->competitor_label( $rule )
		);
	}

	/**
	 * Competitor display label: explicit competitor_name, else the first slug.
	 *
	 * @since 2.0.2
	 */
	protected function competitor_label( Competitor_Rule $rule ): string {
		return '' !== $rule->get_competitor_name() ? $rule->get_competitor_name() : $rule->get_detect_slugs()[0];
	}

	/**
	 * Smart recommend primary-action URL. Connected + owned → in-admin catalog;
	 * otherwise the public product URL. Degrades to our_url whenever the account
	 * is unavailable / not connected / product not owned.
	 *
	 * @since 2.0.2
	 */
	protected function get_recommend_action_url( Competitor_Rule $rule ): string {

		if (
			$rule->get_our_download_id() > 0
			&& $this->is_account_connected()
			&& $this->owns_download( $rule->get_our_download_id() )
		) {
			return esc_url_raw( admin_url( 'admin.php?page=woodev-extensions' ) );
		}

		return $rule->get_our_url();
	}

	/**
	 * Nonce'd deactivate link for the active competitor plugin file.
	 *
	 * The plugin file is passed RAW to add_query_arg() (which URL-encodes the
	 * value once); pre-encoding here would double-encode it and break WordPress's
	 * nonce check, which is signed against the raw basename.
	 *
	 * @since 2.0.2
	 *
	 * @param string $plugin_file the active competitor plugin basename
	 */
	protected function get_deactivation_url( string $plugin_file ): string {

		$url = add_query_arg(
			[
				'action'   => 'deactivate',
				'plugin'   => $plugin_file,
				'_wpnonce' => wp_create_nonce( sprintf( 'deactivate-plugin_%s', $plugin_file ) ),
			],
			admin_url( 'plugins.php' )
		);

		return esc_url_raw( $url );
	}

	/**
	 * Whether the Woodev account is connected. Overridable seam for tests.
	 *
	 * @since 2.0.2
	 */
	protected function is_account_connected(): bool {

		if ( ! class_exists( 'Woodev_Account_Connection' ) ) {
			return false;
		}

		return ( new Woodev_Account_Connection() )->is_connected();
	}

	/**
	 * Whether the given download id is owned, read from the purchases transient
	 * cached by the extensions REST controller (no blocking HTTP at render).
	 *
	 * @since 2.0.2
	 */
	protected function owns_download( int $download_id ): bool {

		// Best-effort read of the purchases cache the account REST controller
		// populates; reference its canonical key const so a future rename can't
		// silently desync this lookup. A cold/absent/malformed cache degrades to
		// false → the public our_url (graceful degradation, by design — spec §6).
		$key = class_exists( 'Woodev_REST_API_Account' )
			? \Woodev_REST_API_Account::PURCHASES_CACHE_KEY
			: 'woodev_account_purchases';

		$cached = get_transient( $key );

		if ( ! is_array( $cached ) || ! isset( $cached['purchased'] ) || ! is_array( $cached['purchased'] ) ) {
			return false;
		}

		return in_array( $download_id, array_map( 'intval', $cached['purchased'] ), true );
	}

	/**
	 * Shared dismiss action.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function dismiss_action(): array {
		return [
			'name'  => 'dismiss',
			'label' => 'Скрыть',
		];
	}

	/**
	 * Resolves the renderer: WC Admin Notes when present, else admin-notice
	 * fallback. Cached for the request. Overridable in tests.
	 *
	 * @since 2.0.2
	 */
	protected function get_renderer(): Competitor_Notice_Renderer {

		if ( null === $this->renderer ) {
			$this->renderer = WC_Admin_Notes_Renderer::is_available()
				? new WC_Admin_Notes_Renderer( $this->plugin->get_id_dasherized() )
				: new Admin_Notice_Renderer( $this->plugin->get_admin_notice_handler() );
		}

		return $this->renderer;
	}

	/**
	 * @since 2.0.2
	 *
	 * @return Woodev_Plugin
	 */
	public function get_plugin(): Woodev_Plugin {
		return $this->plugin;
	}
}
