<?php
/**
 * The printable page of PNG stickers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained HTML page with every sticker of a {@see Label_Bundle}, a print button and a hint about the print settings of a
 * label printer. The images travel inside the page, so nothing is stored on disk and nothing else is requested.
 *
 * A sticker that says its size is printed at that size. On a sheet the stickers stand side by side and none is cut by a page
 * break; on paper no wider than 80 mm (a label printer) each sticker starts at the page edge, one per label when the print
 * dialog's margins are off.
 *
 * @since 2.1.28
 */
final class Label_Sheet {
	private const STYLES = 'body{margin:0;padding:16px;font:14px/1.4 sans-serif;color:#1d2327;background:#fff}'
		. '.toolbar{margin-bottom:16px}.toolbar button{padding:8px 20px;font:inherit;cursor:pointer}'
		. '.toolbar p{margin:8px 0 0;color:#50575e}'
		. '.label{display:inline-block;vertical-align:top;margin:0 4mm 4mm 0;line-height:0;break-inside:avoid;page-break-inside:avoid}'
		. '.label img{display:block;max-width:100%}'
		. '@media print{body{padding:0}.toolbar{display:none}}'
		. '@media print and (max-width:80mm){.label{display:block;margin:0}}';

	/**
	 * Builds the page.
	 *
	 * @since 2.1.28
	 *
	 * @param Label_File[] $files PNG stickers.
	 * @return string The whole HTML document, escaped.
	 */
	public static function html( array $files ): string {
		$labels = '';

		foreach ( $files as $file ) {
			$labels .= sprintf(
				'<div class="label"><img src="data:%1$s;base64,%2$s" alt="%3$s"%4$s></div>',
				Label_File::PNG,
				base64_encode( $file->body() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image inside the page.
				esc_attr( $file->name() ),
				self::size_attribute( $file )
			);
		}

		return sprintf(
			'<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>%1$s</title><style>%2$s</style></head><body>'
			. '<div class="toolbar"><button type="button" onclick="window.print()">%3$s</button><p>%4$s</p></div><main>%5$s</main></body></html>',
			esc_html__( 'Этикетки для печати', 'woodev-plugin-framework' ),
			self::STYLES,
			esc_html__( 'Напечатать', 'woodev-plugin-framework' ),
			esc_html__( 'Для принтера этикеток: в окне печати выберите поля «Нет» и масштаб 100 %.', 'woodev-plugin-framework' ),
			$labels
		);
	}

	/**
	 * @param Label_File $file Sticker.
	 * @return string The `style` attribute that prints it at its size; empty when it says none.
	 */
	private static function size_attribute( Label_File $file ): string {
		if ( 0 === $file->width_mm() || 0 === $file->height_mm() ) {
			return '';
		}

		return sprintf( ' style="width:%1$dmm;height:%2$dmm"', $file->width_mm(), $file->height_mm() );
	}
}
