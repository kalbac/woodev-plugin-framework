<?php
/**
 * Rule: the card has an image the marketplace can fetch.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog\Rules;

use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace downloads images itself, so a URL must be absolute `https` on a public host. A shop on `localhost`, a `.local` /
 * `.test` development domain or a private address can never be fetched. One usable image is enough.
 *
 * @since 2.0.29
 */
final class Images_Rule implements Validation_Rule {
	public const CODE_MISSING    = 'image_missing';
	public const CODE_NOT_PUBLIC = 'image_not_public';
	public const CODE_SOURCE     = 'image_source_unsupported';

	/**
	 * Host suffixes that never resolve on the public internet.
	 *
	 * @var string[]
	 */
	private const LOCAL_SUFFIXES = [ '.localhost', '.local', '.test', '.internal', '.lan', '.home', '.invalid', '.example' ];

	/**
	 * Checks the product.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product        $product Product.
	 * @param Mapping            $mapping Mapping.
	 * @param Profile            $profile Profile.
	 * @param Validation_Context $context Context.
	 * @param Validation_Report  $report  Report so far.
	 * @return Validation_Report
	 */
	public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
		$unsupported = $context->unsupported_image_sources();

		if ( [] !== $unsupported ) {
			return $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_SOURCE,
				'В профиле для фото выбрано «' . $unsupported[0] . '», а это не изображение — выберите «Главное фото» или «Галерея».',
				null,
				Validation_Report::FIX_PROFILE,
				'в профиле неверный источник фото'
			);
		}

		$urls = $context->image_urls();

		if ( [] === $urls ) {
			return $report->with_issue(
				Validation_Report::ERROR,
				self::CODE_MISSING,
				'У товара нет изображения — добавьте главное фото в карточке товара: ' . $context->marketplace( 'маркетплейс' ) . ' скачивает картинки по ссылке.',
				null,
				Validation_Report::FIX_PRODUCT,
				'нет фото'
			);
		}

		foreach ( $urls as $url ) {
			if ( self::is_public_https( $url ) ) {
				return $report;
			}
		}

		return $report->with_issue(
			Validation_Report::ERROR,
			self::CODE_NOT_PUBLIC,
			'Ссылку на изображение (' . $urls[0] . ') ' . $context->marketplace( 'маркетплейс' ) . ' не сможет скачать: нужен адрес https:// на открытом сайте, не localhost и не адрес из локальной сети.',
			null,
			Validation_Report::FIX_PRODUCT,
			'ссылки на фото недоступны из интернета'
		);
	}

	/**
	 * Tells whether a URL is absolute `https` on a public host.
	 *
	 * @since 2.0.29
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_public_https( string $url ): bool {
		$parts = parse_url( trim( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The layer calls no WordPress function.

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = rtrim( strtolower( trim( (string) $parts['host'], '[]' ) ), '.' );

		if ( '' === $host ) {
			return false;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		if ( 1 === preg_match( '/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+))*$/', $host ) ) {
			return false; // Numeric spellings such as 127.1 or 2130706433 are local addresses in disguise.
		}

		return false !== strpos( $host, '.' ) && 'localhost' !== $host && ! self::has_local_suffix( $host );
	}

	/**
	 * Tells whether a host name ends with a development-only suffix.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private static function has_local_suffix( string $host ): bool {
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
