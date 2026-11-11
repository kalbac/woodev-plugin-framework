<?php
/**
 * Secret and token redactor for diagnostics sections, support reports and API logs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Diagnostics\Report_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Scrubs secret credentials, auth headers, webhook tokens, adapter-declared secret field names and known plaintext
 * secret values out of diagnostics sections and free-form log/report text.
 *
 * Complements {@see \Woodev\Framework\Marketplace\Diagnostics\Support_Report}: `Support_Report` masks secret-labelled
 * lines in the pure domain layer, while `Report_Redactor` additionally scrubs inline headers, URL tokens, JSON/query
 * pairs, custom adapter secret keys and live account secrets before anything reaches the screen or clipboard.
 *
 * @since 2.1.2
 */
final class Report_Redactor {
	public const MASK = '[скрыто]';

	private const BUILTIN_SECRET_KEYS = [
		'api_key',
		'api-key',
		'x_api_key',
		'x-api-key',
		'client_secret',
		'client-secret',
		'access_token',
		'refresh_token',
		'notification_token',
		'signing_secret',
		'token',
		'secret',
		'password',
		'authorization',
	];

	private const SECRET_LABEL_PATTERN = '/api[\s_-]?key|api[\s_-]?ключ|ключ[\s_-]?api|token|токен|secret|секрет|password|парол|authorization/iu';

	/**
	 * Redacts secrets inside free-form text (error messages, URLs, HTTP dumps, support report text).
	 *
	 * @since 2.1.2
	 *
	 * @param string   $text               Input text.
	 * @param string[] $secret_field_names Adapter-declared secret field keys (`Credential_Fields::secrets()`).
	 * @param string[] $known_secrets      Known plaintext secrets/tokens of loaded accounts to scrub verbatim.
	 * @return string
	 */
	public static function redact_text( string $text, array $secret_field_names = [], array $known_secrets = [] ): string {
		if ( '' === $text ) {
			return '';
		}

		// 1. Scrub known plaintext secret/token values first (longest first so prefixes do not leave tails).
		$literals = self::normalized_literals( $known_secrets );
		foreach ( $literals as $literal ) {
			$text = str_replace( $literal, self::MASK, $text );
		}

		// 2. Notification webhook URLs: `/notifications/{account_id}/{secret_token}`.
		$text = (string) preg_replace(
			'#(/notifications/\d+/)[A-Za-z0-9_-]{8,}#u',
			'$1' . self::MASK,
			$text
		);

		// 3. Bearer / Basic schemes inside Authorization headers or inline error dumps.
		$text = (string) preg_replace(
			'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/iu',
			'$1 ' . self::MASK,
			$text
		);

		$keys    = self::key_alternation( $secret_field_names );
		$headers = self::header_alternation( $secret_field_names );

		// 4. JSON key-value pairs: `"api_key": "..."` or `'custom_secret':'...'`.
		$text = (string) preg_replace(
			'/([\'"])(' . $keys . ')\1(\s*:\s*)([\'"])(?:\\\\.|(?!\4).)*\4/iu',
			'$1$2$1$3$4' . self::MASK . '$4',
			$text
		);

		// 5. Query-string / key=value pairs: `api_key=abc123&` or `custom_secret=xyz`.
		$text = (string) preg_replace(
			'/(?<![\p{L}\p{N}_-])(' . $keys . ')(=)([^\s&"\'<>]+)/iu',
			'$1$2' . self::MASK,
			$text
		);

		// 6. PHP print_r / var_export array entries (`[Api-Key] => value`, `['api_key'] => 'value'`).
		$text = (string) preg_replace_callback(
			'/(\[\s*[\'"]?(?:' . $keys . '|' . $headers . ')[\'"]?\s*\]\s*=>\s*)(?!\[скрыто\])([^\r\n]+)/iu',
			static function ( array $m ): string {
				return $m[1] . self::MASK;
			},
			$text
		);

		// 7. HTTP header lines or inline header pairs (`Api-Key: value`, `X-Api-Key: value`, custom secret headers).
		$text = (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}_-])(' . $headers . ')(\s*:\s*)(?!\[скрыто\])([^\r\n,;]+)/iu',
			static function ( array $m ): string {
				return $m[1] . $m[2] . self::MASK;
			},
			$text
		);

		return $text;
	}

	/**
	 * Redacts every label/value line of a {@see Report_Section}.
	 *
	 * @since 2.1.2
	 *
	 * @param Report_Section $section            Section to scrub.
	 * @param string[]       $secret_field_names Adapter-declared secret field keys.
	 * @param string[]       $known_secrets      Known plaintext secrets/tokens.
	 * @param string[]       $secret_labels      Additional localized labels of secret fields (`Credential_Field_Spec::label()`).
	 * @return Report_Section
	 */
	public static function redact_section(
		Report_Section $section,
		array $secret_field_names = [],
		array $known_secrets = [],
		array $secret_labels = []
	): Report_Section {
		$lines        = [];
		$lower_names  = array_map( 'mb_strtolower', array_merge( self::BUILTIN_SECRET_KEYS, $secret_field_names, $secret_labels ) );
		$lower_lookup = array_flip( array_filter( $lower_names, 'strlen' ) );

		foreach ( $section->lines() as $label => $value ) {
			$label_str = (string) $label;
			$value_str = (string) $value;

			if ( self::is_secret_label( $label_str, $lower_lookup ) ) {
				$lines[ $label_str ] = '' === trim( $value_str ) ? '' : self::MASK;
				continue;
			}

			$lines[ $label_str ] = self::redact_text( $value_str, $secret_field_names, $known_secrets );
		}

		return new Report_Section(
			self::redact_text( $section->title(), $secret_field_names, $known_secrets ),
			$lines
		);
	}

	/**
	 * Tells whether a section line label names a secret field.
	 *
	 * @param string             $label        Line label.
	 * @param array<string, int> $lower_lookup Lower-cased secret keys and labels.
	 * @return bool
	 */
	private static function is_secret_label( string $label, array $lower_lookup ): bool {
		$normalized = mb_strtolower( trim( $label ) );

		if ( isset( $lower_lookup[ $normalized ] ) ) {
			return true;
		}

		return 1 === preg_match( self::SECRET_LABEL_PATTERN, $label );
	}

	/**
	 * Filters and sorts plaintext secrets longest-first; ignores values shorter than 4 characters to avoid false matches.
	 *
	 * @param string[] $known_secrets Candidate plaintext secrets.
	 * @return string[]
	 */
	private static function normalized_literals( array $known_secrets ): array {
		$filtered = [];

		foreach ( $known_secrets as $secret ) {
			if ( ! is_string( $secret ) ) {
				continue;
			}
			$trimmed = trim( $secret );
			if ( strlen( $trimmed ) >= 4 ) {
				$filtered[ $trimmed ] = $trimmed;
			}
		}

		$list = array_values( $filtered );
		usort(
			$list,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $list;
	}

	/**
	 * Builds a regex alternation for secret field keys (both `_` and `-` forms).
	 *
	 * @param string[] $extra Adapter secret field names.
	 * @return string
	 */
	private static function key_alternation( array $extra ): string {
		$variants = self::BUILTIN_SECRET_KEYS;

		foreach ( $extra as $name ) {
			$clean = trim( (string) $name );
			if ( '' === $clean ) {
				continue;
			}
			$variants[] = $clean;
			$variants[] = str_replace( '_', '-', $clean );
			$variants[] = str_replace( '-', '_', $clean );
		}

		$escaped = array_map(
			static function ( string $key ): string {
				return preg_quote( $key, '/' );
			},
			array_values( array_unique( $variants ) )
		);

		return implode( '|', $escaped );
	}

	/**
	 * Builds a regex alternation for HTTP header names that carry credentials.
	 *
	 * @param string[] $extra Adapter secret field names.
	 * @return string
	 */
	private static function header_alternation( array $extra ): string {
		$headers = [
			'Authorization',
			'Api-Key',
			'X-Api-Key',
			'Client-Secret',
			'X-Client-Secret',
			'X-Auth-Token',
		];

		foreach ( $extra as $name ) {
			$clean = trim( (string) $name );
			if ( '' === $clean ) {
				continue;
			}
			$headers[] = $clean;
			$headers[] = str_replace( '_', '-', $clean );
		}

		$escaped = array_map(
			static function ( string $header ): string {
				return preg_quote( $header, '/' );
			},
			array_values( array_unique( $headers ) )
		);

		return implode( '|', $escaped );
	}
}
