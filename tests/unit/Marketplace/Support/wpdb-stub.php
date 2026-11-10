<?php
/**
 * Minimal `wpdb` so that code type-hinted against it loads without WordPress.
 *
 * Only loaded when no real one exists; PHPStan does not analyse it (the WordPress stubs declare the real class).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

if ( ! class_exists( 'wpdb' ) ) {
	// phpcs:disable Squiz.Commenting.ClassComment.Missing,Generic.Commenting.DocComment.MissingShort,Squiz.Classes.ValidClassName.NotCapital,Squiz.Classes.ValidClassName.NotCamelCaps,PEAR.NamingConventions.ValidClassName -- The WordPress class name.
	class wpdb {
		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';
	}
}
