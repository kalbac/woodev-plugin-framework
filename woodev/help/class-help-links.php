<?php
/**
 * «Подробнее…» help links: topic declarations resolved against a plugin's topic table.
 *
 * @package Woodev\Framework\Help
 */

namespace Woodev\Framework\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a help declaration (a topic, optionally with a link-text variant) into the `{ url, text }` pair the
 * admin UI draws as a «Подробнее…» link.
 *
 * The code never holds an address: a field, section, group, page or note names a TOPIC, and the plugin owns ONE
 * table «topic → absolute URL with the section anchor» ({@see \Woodev_Plugin::get_help_topics()}). A moved article
 * is a one-line edit in that table. A topic the table does not know resolves to nothing, so no dead link is drawn;
 * {@see self::missing_topics()} lets a plugin test catch the typo instead.
 *
 * A declaration is a topic string (`'import'`) or `[ 'topic' => 'import', 'text' => Help_Links::TEXT_HOW_TO_SET_UP ]`.
 *
 * @since 2.1.11
 */
final class Help_Links {
	/**
	 * Link text «Подробнее как это работает» — the default.
	 *
	 * @since 2.1.11
	 */
	public const TEXT_HOW_IT_WORKS = 'how_it_works';

	/**
	 * Link text «Подробнее как настроить».
	 *
	 * @since 2.1.11
	 */
	public const TEXT_HOW_TO_SET_UP = 'how_to_set_up';

	/**
	 * Link text «Подробнее для чего это нужно».
	 *
	 * @since 2.1.11
	 */
	public const TEXT_WHY_NEEDED = 'why_needed';

	/**
	 * Link text for a note or an error: points at the «Частые проблемы» section of an article.
	 *
	 * @since 2.1.11
	 */
	public const TEXT_TROUBLESHOOTING = 'troubleshooting';

	/**
	 * The variant keys, without translating anything — a declaration is validated on every request, the wording only when a link is drawn.
	 *
	 * @since 2.1.11
	 */
	private const VARIANTS = [ self::TEXT_HOW_IT_WORKS, self::TEXT_HOW_TO_SET_UP, self::TEXT_WHY_NEEDED, self::TEXT_TROUBLESHOOTING ];

	/**
	 * The link texts a declaration may pick from; the set is fixed so the wording stays uniform across plugins.
	 *
	 * @since 2.1.11
	 *
	 * @return array<string,string> variant => link text.
	 */
	public static function texts(): array {
		return [
			self::TEXT_HOW_IT_WORKS    => __( 'Подробнее как это работает', 'woodev-plugin-framework' ),
			self::TEXT_HOW_TO_SET_UP   => __( 'Подробнее как настроить', 'woodev-plugin-framework' ),
			self::TEXT_WHY_NEEDED      => __( 'Подробнее для чего это нужно', 'woodev-plugin-framework' ),
			self::TEXT_TROUBLESHOOTING => __( 'Что делать, если не получается', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * Reads a declaration into `[ topic, text ]`; null when it names no topic.
	 *
	 * An unknown link-text variant falls back to the default one rather than hiding the link.
	 *
	 * @since 2.1.11
	 *
	 * @param mixed $declaration A topic string or `[ 'topic' => …, 'text' => … ]`.
	 * @return array{topic:string,text:string}|null
	 */
	public static function declaration( $declaration ): ?array {
		$topic   = is_array( $declaration ) ? ( $declaration['topic'] ?? '' ) : $declaration;
		$variant = is_array( $declaration ) ? ( $declaration['text'] ?? '' ) : '';

		if ( ! is_string( $topic ) || '' === trim( $topic ) ) {
			return null;
		}

		return [
			'topic' => trim( $topic ),
			'text'  => is_string( $variant ) && in_array( $variant, self::VARIANTS, true ) ? $variant : self::TEXT_HOW_IT_WORKS,
		];
	}

	/**
	 * Resolves a declaration against a topic table.
	 *
	 * @since 2.1.11
	 *
	 * @param mixed                $declaration A topic string or `[ 'topic' => …, 'text' => … ]`.
	 * @param array<string,string> $topics      The plugin's table, topic => URL ({@see self::topics_of()}).
	 * @return array{url:string,text:string,label:string}|null `label` is the accessible name (the text and a note that
	 *                                                          the link opens in a new tab). Null when nothing is declared
	 *                                                          or the table does not know the topic.
	 */
	public static function resolve( $declaration, array $topics ): ?array {
		$declared = self::declaration( $declaration );

		if ( null === $declared || ! isset( $topics[ $declared['topic'] ] ) ) {
			return null;
		}

		$text = self::texts()[ $declared['text'] ];

		return [
			'url'   => $topics[ $declared['topic'] ],
			'text'  => $text,
			/* translators: %s: link text, e.g. «Подробнее как настроить». */
			'label' => sprintf( __( '%s (откроется в новой вкладке)', 'woodev-plugin-framework' ), $text ),
		];
	}

	/**
	 * The plugin's usable topic table: topic => absolute http(s) URL. An entry that is not a non-empty topic mapped
	 * to an absolute URL is dropped, so a malformed row costs one link, never a broken page.
	 *
	 * @since 2.1.11
	 *
	 * @param object|null $plugin The owning plugin; null (a framework service) has no table.
	 * @return array<string,string>
	 */
	public static function topics_of( $plugin ): array {
		if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_help_topics' ) || ! method_exists( $plugin, 'get_id' ) ) {
			return [];
		}

		/**
		 * Filters a plugin's help-topic table.
		 *
		 * @since 2.1.11
		 *
		 * @param array<string,string> $topics    Topic => absolute URL with the section anchor.
		 * @param string               $plugin_id The plugin id.
		 */
		$table = apply_filters( 'woodev_help_topics', (array) $plugin->get_help_topics(), (string) $plugin->get_id() );

		$topics = [];

		foreach ( (array) $table as $topic => $url ) {
			if ( is_string( $topic ) && '' !== $topic && is_string( $url ) && 1 === preg_match( '#^https?://\S+$#i', $url ) ) {
				$topics[ $topic ] = $url;
			}
		}

		return $topics;
	}

	/**
	 * The declarations naming a topic the table does not know — the check a plugin test runs over every
	 * declaration it makes, so a typo fails a test instead of silently drawing no link.
	 *
	 * @since 2.1.11
	 *
	 * @param array<int,mixed>     $declarations Declarations (topic strings or arrays).
	 * @param array<string,string> $topics       The plugin's table.
	 * @return string[] The unknown topics, without repeats.
	 */
	public static function missing_topics( array $declarations, array $topics ): array {
		$missing = [];

		foreach ( $declarations as $declaration ) {
			$declared = self::declaration( $declaration );

			if ( null !== $declared && ! isset( $topics[ $declared['topic'] ] ) ) {
				$missing[] = $declared['topic'];
			}
		}

		return array_values( array_unique( $missing ) );
	}
}
