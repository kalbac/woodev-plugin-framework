<?php
/**
 * Export profile value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A reusable set of export defaults: sources for the article, barcode, description and images, fallback dimensions
 * and weight, VAT, currency rule and the price rule. The settings stay an array; each consumer reads its own keys.
 *
 * @since 2.0.29
 */
final class Profile {
	/**
	 * Profile id, null before it is stored.
	 *
	 * @var int|null
	 */
	private $id;

	/**
	 * Merchant's label.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Creates a profile.
	 *
	 * @since 2.0.29
	 *
	 * @param int|null            $id       Profile id.
	 * @param string              $name     Merchant's label.
	 * @param array<string,mixed> $settings Settings.
	 */
	public function __construct( ?int $id, string $name, array $settings = [] ) {
		$this->id       = $id;
		$this->name     = $name;
		$this->settings = $settings;
	}

	/**
	 * Reads one setting.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $fallback Value when the setting is not set.
	 * @return mixed
	 */
	public function setting( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}

	/**
	 * Returns the profile id, or null before it is stored.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Returns the merchant's label.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns every setting.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}
}
