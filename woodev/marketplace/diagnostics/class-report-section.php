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
	 * How the admin screen shows some lines, by label. The support report text ignores it.
	 *
	 * @var array<string,array{monospace?:bool,link?:array{text:string,url:string}}>
	 */
	private $presentation;

	/**
	 * Creates a section.
	 *
	 * @since 2.0.29
	 *
	 * @param string                                                                           $title        Section title.
	 * @param array<string,string>                                                             $lines        Label to value.
	 * @param array<string,array{monospace?:bool,link?:array{text:string,url:string}}>|array{} $presentation Since 2.1.13: per label, `monospace`
	 *                                                                                                        for a value shown as code and `link` for a
	 *                                                                                                        link after it (text and URL).
	 */
	public function __construct( string $title, array $lines, array $presentation = [] ) {
		$this->title        = $title;
		$this->lines        = $lines;
		$this->presentation = $presentation;
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

	/**
	 * Returns how the admin screen shows some lines, by label.
	 *
	 * @since 2.1.13
	 *
	 * @return array<string,array{monospace?:bool,link?:array{text:string,url:string}}>
	 */
	public function presentation(): array {
		return $this->presentation;
	}
}
