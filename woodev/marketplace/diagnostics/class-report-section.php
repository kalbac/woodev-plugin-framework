<?php
/**
 * Support report section.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * A titled list of label and value lines for the support report.
 *
 * @since 2.0.29
 */
final class Report_Section {
	/**
	 * Section title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Lines, label to value.
	 *
	 * @var array<string,string>
	 */
	private $lines;

	/**
	 * Creates a section.
	 *
	 * @since 2.0.29
	 *
	 * @param string               $title Section title.
	 * @param array<string,string> $lines Label to value.
	 */
	public function __construct( string $title, array $lines ) {
		$this->title = $title;
		$this->lines = $lines;
	}

	/**
	 * Returns the title.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Returns the lines.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,string>
	 */
	public function lines(): array {
		return $this->lines;
	}
}
