<?php
/**
 * The JSON shape of the diagnostics sections.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Diagnostics;

use Woodev\Framework\Marketplace\Diagnostics\Report_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Turns report sections into the rows the diagnostics view renders: a title and a list of label/value lines, every value
 * a string. The sections are already redacted by {@see \Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder};
 * this only reshapes them, so nothing the builder hid can come back here.
 *
 * @since 2.1.5
 */
final class Diagnostics_Payload {
	/**
	 * Sections as `[ 'title' => string, 'lines' => [ [ 'label' => string, 'value' => string ], … ] ]`.
	 *
	 * @since 2.1.5
	 *
	 * @param Report_Section[] $sections Sections of the report builder.
	 * @return array<int,array{title:string,lines:array<int,array{label:string,value:string}>}>
	 */
	public static function sections( array $sections ): array {
		$payload = [];

		foreach ( $sections as $section ) {
			$payload[] = [
				'title' => $section->title(),
				'lines' => self::lines( $section->lines() ),
			];
		}

		return $payload;
	}

	/**
	 * Label/value pairs of one section, in their order.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string|int,mixed> $lines Lines keyed by label.
	 * @return array<int,array{label:string,value:string}>
	 */
	private static function lines( array $lines ): array {
		$rows = [];

		foreach ( $lines as $label => $value ) {
			$rows[] = [
				'label' => (string) $label,
				'value' => (string) $value,
			];
		}

		return $rows;
	}
}
