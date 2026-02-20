<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class Woodev_Admin_Plugins {


	/**
	 * Get sections for the addons screen
	 *
	 * @return array of objects
	 */
	public static function get_sections() {

		$addon_sections = get_transient( 'woodev_extensions_sections' );

		if ( false === ( $addon_sections ) ) {

			$raw_sections = wp_safe_remote_get( 'https://woodev.ru/edd-api/v2/categories' );

			if ( ! is_wp_error( $raw_sections ) ) {

				$body = json_decode( wp_remote_retrieve_body( $raw_sections ) );

				if ( $body && isset( $body->categories ) ) {

					$addon_sections = $body->categories;

					set_transient( 'woodev_extensions_sections', $addon_sections, WEEK_IN_SECONDS );
				}
			}
		}

		return $addon_sections;
	}

	public static function get_extension_by_query() {

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : 'all';
		$search  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		if ( ! empty( $search ) ) {
			$section          = 'all';
			$transient_suffix = md5( json_encode( array( 'section' => $section, 'search' => $search ) ) );
		} else {
			$transient_suffix = $section;
		}

		$transient_name = sprintf( 'woodev_extensions_%s', $transient_suffix );

		$addons = get_transient( $transient_name );

		//delete_transient( $transient_name );

		if ( false === $addons ) {

			$parameters = array();

			if ( ! empty( $search ) ) {
				$parameters['s'] = $search;
			} elseif ( 'all' !== $section ) {
				$parameters['category'] = $section;
			}

			$parameters['number'] = - 1;

			$url = add_query_arg( $parameters, 'https://woodev.ru/edd-api/v2/products/' );

			$raw_extensions = wp_safe_remote_get( $url );

			if ( ! is_wp_error( $raw_extensions ) && 200 == wp_remote_retrieve_response_code( $raw_extensions ) ) {

				$body = json_decode( wp_remote_retrieve_body( $raw_extensions ) );

				if ( $body && isset( $body->products ) ) {

					$addons = $body->products;

					set_transient( $transient_name, $addons, WEEK_IN_SECONDS );
				}
			}
		}

		return $addons;
	}

	public static function get_all_extension() {

		$addons = get_transient( 'woodev_extensions' );

		if ( false === $addons ) {

			$url = add_query_arg( array( 'number' => 30 ), 'https://woodev.ru/edd-api/v2/products/' );

			$raw_extensions = wp_safe_remote_get( $url );

			if ( ! is_wp_error( $raw_extensions ) && 200 == wp_remote_retrieve_response_code( $raw_extensions ) ) {

				$body = json_decode( wp_remote_retrieve_body( $raw_extensions ) );

				if ( $body && isset( $body->products ) ) {

					$addons = $body->products;

					set_transient( 'woodev_extensions', $addons, WEEK_IN_SECONDS );
				}
			}
		}

		return $addons;
	}

	public static function generate_utm_url( $url = '', $content = '' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'extensionsscreen',
				'utm_medium'   => 'product',
				'utm_campaign' => 'woodevplugin',
				'utm_content'  => $content,
			),
			$url
		);
	}

	public static function output() {

		$section  = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : 'all';
		$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$sections = self::get_sections();

		if ( 'all' == $section && empty( $search ) ) {
			$addons = self::get_all_extension() ?: array();
		} else {
			$addons = self::get_extension_by_query() ?: array();
		}

		include_once dirname( __FILE__ ) . '/views/html-admin-page-plugins.php';
	}
}