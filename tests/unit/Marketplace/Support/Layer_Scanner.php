<?php
/**
 * Dependency scanner for the marketplace layer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

/**
 * Reads PHP source with the tokenizer and reports everything that ties a file to something outside the marketplace
 * layer. The layer is the framework's marketplace domain (`woodev/marketplace/{account,capabilities,...}`), so it may depend only on:
 *
 * - itself (`Woodev\Framework\Marketplace\*`);
 * - PHP itself (internal classes, functions and constants);
 * - `WC_Product`, `WC_Order` and `wpdb`.
 *
 * It must not mention Ozon in code (identifiers or strings; comments may use Ozon as an example), call any WordPress
 * function, use a WordPress constant, or import another namespace. The scan is on tokens, so a word inside a comment
 * or docblock is never a dependency.
 */
final class Layer_Scanner {
	/**
	 * Global classes the layer may name.
	 *
	 * @var string[]
	 */
	private const ALLOWED_GLOBAL_CLASSES = array( 'WC_Product', 'WC_Order', 'wpdb' );

	/**
	 * Namespace root of the layer.
	 */
	private const LAYER_ROOT = 'Woodev\\Framework\\Marketplace\\';

	/**
	 * Lists the violations in a piece of source.
	 *
	 * @param string $source PHP source.
	 * @return string[] One message per violation; empty when the source is clean.
	 */
	public static function violations( string $source ): array {
		$violations = array();
		$tokens     = self::significant_tokens( $source );
		$count      = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
				$violations = array_merge( $violations, self::string_violations( $token[1] ) );
				continue;
			}

			if ( ! self::is_name_part( $token[0] ) ) {
				continue;
			}

			$name  = '';
			$start = $i;
			while ( $i < $count && self::is_name_part( $tokens[ $i ][0] ) ) {
				$name .= $tokens[ $i ][1];
				++$i;
			}

			$violations = array_merge( $violations, self::name_violations( $name, self::text_at( $tokens, $start - 1 ), self::text_at( $tokens, $i ) ) );
			--$i;
		}

		return $violations;
	}

	/**
	 * Returns the fully qualified name of the first class, interface or trait the source declares.
	 *
	 * @param string $source PHP source.
	 * @return string|null Null when the source declares none.
	 */
	public static function declared_symbol( string $source ): ?string {
		$tokens    = self::significant_tokens( $source );
		$namespace = '';
		$count     = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( T_NAMESPACE === $tokens[ $i ][0] ) {
				$namespace = self::read_name( $tokens, $i + 1 );
			}

			if ( in_array( $tokens[ $i ][0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) && isset( $tokens[ $i + 1 ] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				return $namespace . '\\' . $tokens[ $i + 1 ][1];
			}
		}

		return null;
	}

	/**
	 * Tokenizes source and drops whitespace and comments.
	 *
	 * @param string $source PHP source.
	 * @return array<int,array{0:int|string,1:string}> Tokens; single characters are given as [char, char].
	 */
	private static function significant_tokens( string $source ): array {
		$tokens = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG ), true ) ) {
					continue;
				}

				$tokens[] = array( $token[0], $token[1] );
				continue;
			}

			$tokens[] = array( $token, $token );
		}

		return $tokens;
	}

	/**
	 * Tells whether a token can be a piece of a (qualified) name; PHP 8 merges the pieces into one token.
	 *
	 * @param int|string $id Token id.
	 * @return bool
	 */
	private static function is_name_part( $id ): bool {
		$parts = array( T_STRING, T_NS_SEPARATOR );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
			if ( defined( $constant ) ) {
				$parts[] = constant( $constant );
			}
		}

		return in_array( $id, $parts, true );
	}

	/**
	 * Returns the text of a token, or an empty string outside the list.
	 *
	 * @param array<int,array{0:int|string,1:string}> $tokens Tokens.
	 * @param int                                     $index  Index.
	 * @return string
	 */
	private static function text_at( array $tokens, int $index ): string {
		return $tokens[ $index ][1] ?? '';
	}

	/**
	 * Reads a name that starts at an index.
	 *
	 * @param array<int,array{0:int|string,1:string}> $tokens Tokens.
	 * @param int                                     $start  Index of the first part.
	 * @return string
	 */
	private static function read_name( array $tokens, int $start ): string {
		$name = '';
		$i    = $start;
		while ( isset( $tokens[ $i ] ) && self::is_name_part( $tokens[ $i ][0] ) ) {
			$name .= $tokens[ $i ][1];
			++$i;
		}

		return $name;
	}

	/**
	 * Checks a string literal.
	 *
	 * @param string $literal String token text.
	 * @return string[]
	 */
	private static function string_violations( string $literal ): array {
		return false !== stripos( $literal, 'ozon' ) ? array( 'Ozon-specific string: ' . $literal ) : array();
	}

	/**
	 * Checks one name found in code.
	 *
	 * @param string $name     Name as written.
	 * @param string $previous Text of the token before the name.
	 * @param string $next     Text of the token after the name.
	 * @return string[]
	 */
	private static function name_violations( string $name, string $previous, string $next ): array {
		if ( false !== stripos( $name, 'ozon' ) ) {
			return array( 'Ozon-specific name: ' . $name );
		}

		if ( in_array( $previous, array( '->', '?->', '::', 'function', 'const', 'class', 'interface', 'trait' ), true ) ) {
			return array();
		}

		if ( 'namespace' === $previous || 'use' === $previous ) {
			return self::is_layer_or_internal( ltrim( $name, '\\' ), 'namespace' === $previous ) ? array() : array( 'Foreign namespace: ' . $name );
		}

		if ( '(' === $next && 'new' !== $previous ) {
			return self::is_internal_function( ltrim( $name, '\\' ) ) ? array() : array( 'Non-PHP function call: ' . $name . '()' );
		}

		if ( '\\' === substr( $name, 0, 1 ) ) {
			return self::is_layer_or_internal( ltrim( $name, '\\' ), false ) ? array() : array( 'Foreign class: ' . $name );
		}

		if ( false === strpos( $name, '\\' ) && 1 === preg_match( '/^[A-Z][A-Z0-9_]+$/', $name ) && '::' !== $next && ! self::is_internal_constant( $name ) ) {
			return array( 'Non-PHP constant: ' . $name );
		}

		return array();
	}

	/**
	 * Tells whether a fully qualified name is inside the layer, a PHP symbol or on the allow list.
	 *
	 * @param string $name        Name without a leading backslash.
	 * @param bool   $declaration Whether the name is a `namespace` declaration, which must be inside the layer.
	 * @return bool
	 */
	private static function is_layer_or_internal( string $name, bool $declaration ): bool {
		if ( 0 === strpos( $name . '\\', self::LAYER_ROOT ) || 0 === strpos( $name, self::LAYER_ROOT ) ) {
			return true;
		}

		if ( $declaration || false !== strpos( $name, '\\' ) ) {
			return false;
		}

		return in_array( $name, self::ALLOWED_GLOBAL_CLASSES, true ) || self::is_internal_class( $name );
	}

	/**
	 * Tells whether a class or interface is built into PHP.
	 *
	 * @param string $name Class name.
	 * @return bool
	 */
	private static function is_internal_class( string $name ): bool {
		if ( ! class_exists( $name, false ) && ! interface_exists( $name, false ) ) {
			return false;
		}

		return ( new \ReflectionClass( $name ) )->isInternal();
	}

	/**
	 * Tells whether a function is built into PHP.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	private static function is_internal_function( string $name ): bool {
		return function_exists( $name ) && ( new \ReflectionFunction( $name ) )->isInternal();
	}

	/**
	 * Tells whether a constant is defined by PHP or one of its extensions, not by user code such as WordPress.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	private static function is_internal_constant( string $name ): bool {
		foreach ( get_defined_constants( true ) as $category => $constants ) {
			if ( 'user' !== $category && array_key_exists( $name, $constants ) ) {
				return true;
			}
		}

		return false;
	}
}
