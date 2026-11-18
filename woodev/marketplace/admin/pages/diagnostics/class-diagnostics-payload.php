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
	 * Sections as `[ 'title' => string, 'lines' => [ [ 'label' => string, 'value' => string ], … ] ]`; a line can also carry
	 * `monospace` (true) and `link` (`[ 'text' => string, 'url' => string ]`) when the section asked for them.
	 *
	 * @since 2.1.5
	 *
	 * @param Report_Section[] $sections Sections of the report builder.
	 * @return array<int,array{title:string,lines:array<int,array<string,mixed>>}>
	 */
	public static function sections( array $sections ): array {
		$payload = [];

		foreach ( $sections as $section ) {
			$payload[] = [
				'title' => $section->title(),
				'lines' => self::lines( $section->lines(), $section->presentation() ),
			];
		}

		return $payload;
	}

	/**
	 * Label/value pairs of one section, in their order.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string|int,mixed>           $lines        Lines keyed by label.
	 * @param array<string,array<string,mixed>> $presentation How some lines are shown, by label.
	 * @return array<int,array<string,mixed>>
	 */
	private static function lines( array $lines, array $presentation ): array {
		$rows = [];

		foreach ( $lines as $label => $value ) {
			$row = [
				'label' => (string) $label,
				'value' => (string) $value,
			];

			$shown = $presentation[ (string) $label ] ?? [];

			if ( ! empty( $shown['monospace'] ) ) {
				$row['monospace'] = true;
			}

			if ( ! empty( $shown['link'] ) ) {
				$row['link'] = $shown['link'];
			}

			$rows[] = $row;
		}

		return $rows;
	}
}
