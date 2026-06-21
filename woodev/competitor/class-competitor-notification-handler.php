<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Plugin;
use Woodev_Account_Connection;
use Automattic\WooCommerce\Admin\Notes\Note;
use InvalidArgumentException;

/**
 * Platform-neutral competitor-notification engine.
 *
 * A plugin extends this and implements get_competitor_rules(). On each admin
 * screen load run() normalizes every raw rule to a Competitor_Rule, detects
 * whether any competitor slug is active, suppresses recommend rules when our
 * equivalent is installed, then asks the resolved renderer to create/update or
 * delete the note. The renderer is chosen by class_exists( Note::class ) — the
 * gotcha-correct gate, NOT is_enhanced_admin_available() (always true).
 *
 * @since 2.0.2
 */
abstract class Competitor_Notification_Handler {

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

			if ( ! $this->is_competitor_active( $rule ) || $this->is_suppressed( $rule ) ) {
				$renderer->delete( $rule );
				continue;
			}

			$renderer->render( $rule, $this->build_note( $rule ) );
		}
	}

	/**
	 * True when ANY of the rule's detect slugs is an active plugin.
	 *
	 * @since 2.0.2
	 */
	protected function is_competitor_active( Competitor_Rule $rule ): bool {

		foreach ( $rule->get_detect_slugs() as $slug ) {
			if ( $this->plugin->is_plugin_active( $slug ) ) {
				return true;
			}
		}

		return false;
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
	 * @return array<string,mixed>
	 */
	protected function build_note( Competitor_Rule $rule ): array {

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

			$type = $this->note_type_update();

		} else {

			$title   = $rule->get_title_override() ?? 'Информация от Woodev: обнаружен сторонний плагин доставки';
			$content = $rule->get_content_override() ?? $this->default_conflict_content( $rule );

			$actions = [
				[
					'name'    => 'deactivate-plugin',
					'label'   => sprintf( 'Отключить плагин %s', $this->competitor_label( $rule ) ),
					'url'     => $this->get_deactivation_url( $rule ),
					'primary' => true,
				],
				$this->dismiss_action(),
			];

			$type = $this->note_type_error();
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
	 * Nonce'd deactivate link for a conflict rule's first detect slug.
	 *
	 * @since 2.0.2
	 */
	protected function get_deactivation_url( Competitor_Rule $rule ): string {

		$plugin_file = $rule->get_detect_slugs()[0];

		$url = add_query_arg(
			[
				'action'   => 'deactivate',
				'plugin'   => urlencode( $plugin_file ),
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

		$cached = get_transient( 'woodev_account_purchases' );

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
			$this->renderer = class_exists( Note::class )
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

	/**
	 * WC update note type, indirected so the engine has no hard WC dep at unit time.
	 *
	 * @since 2.0.2
	 */
	protected function note_type_update(): string {
		return class_exists( Note::class ) ? Note::E_WC_ADMIN_NOTE_UPDATE : 'update';
	}

	/**
	 * WC error note type, indirected so the engine has no hard WC dep at unit time.
	 *
	 * @since 2.0.2
	 */
	protected function note_type_error(): string {
		return class_exists( Note::class ) ? Note::E_WC_ADMIN_NOTE_ERROR : 'error';
	}
}
