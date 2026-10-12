<?php
/**
 * Minimal stubs of the WooCommerce Admin Notes classes for unit tests.
 *
 * The real \Automattic\WooCommerce\Admin\Notes\Note / Notes and the global
 * WC_Data_Store ship with WooCommerce and are absent from the framework's unit
 * suite. These stubs let a @runInSeparateProcess test exercise the
 * WC_Admin_Notes_Renderer create-path (class_exists( Note::class ) true) and the
 * Woodev_Notes_Helper lookup without a live WooCommerce. The Note stub records
 * setter calls so the test can assert what was built; the data store reports no
 * existing notes so the renderer builds a fresh one. All guarded so they never
 * collide with a real definition.
 *
 * @package Woodev\Tests\Unit
 */

namespace Automattic\WooCommerce\Admin\Notes {

	if ( ! class_exists( Note::class ) ) {

		class Note {

			const E_WC_ADMIN_NOTE_UPDATE     = 'update';
			const E_WC_ADMIN_NOTE_ERROR      = 'error';
			const E_WC_ADMIN_NOTE_UNACTIONED = 'unactioned';
			const E_WC_ADMIN_NOTE_ACTIONED   = 'actioned';

			/** @var array<int,array<string,mixed>> saved note snapshots */
			public static $saved = [];
			/** @var array<string,Note> notes saved by their name */
			public static $by_name = [];

			/** @var array<string,mixed> captured setter values */
			public $a = [];

			public function __construct() {
				$this->a = [ 'layout' => 'plain', 'status' => self::E_WC_ADMIN_NOTE_UNACTIONED, 'acts' => [] ];
			}

			public function set_name( $v ) {
				$this->a['name'] = $v;
			}
			public function set_title( $v ) {
				$this->a['title'] = $v;
			}
			public function get_title() {
				return $this->a['title'] ?? '';
			}
			public function set_content( $v ) {
				$this->a['content'] = $v;
			}
			public function get_content() {
				return $this->a['content'] ?? '';
			}
			public function set_source( $v ) {
				$this->a['source'] = $v;
			}
			public function set_type( $v ) {
				$this->a['type'] = $v;
			}
			public function get_type() {
				return $this->a['type'] ?? '';
			}
			public function get_status() {
				return $this->a['status'];
			}
			public function get_actions() {
				return array_map(
					static function ( $action ) {
						return (object) [
							'name'          => $action[0],
							'label'         => $action[1],
							'query'         => $action[2],
							'status'        => $action[3],
							'is_primary'    => $action[4],
							'actioned_text' => $action[5],
						];
					},
					$this->a['acts']
				);
			}
			public function clear_actions() {
				$this->a['acts'] = [];
			}
			public function add_action( $n, $l, $u, $s = null, $p = false, $t = '' ) {
				$this->a['acts'][] = [ $n, $l, $u, $s, $p, $t ];
			}
			public function save() {
				self::$saved[] = $this->a;
				self::$by_name[ $this->a['name'] ] = $this;
			}
		}
	}

	if ( ! class_exists( Notes::class ) ) {

		class Notes {
			/** @var string[] names passed to delete_notes_with_name() */
			public static $deleted = [];
			public static function get_note( $id ) {
				return Note::$by_name[ $id ] ?? null;
			}

			public static function delete_notes_with_name( $name ) {
				self::$deleted[] = $name;
			}
		}
	}
}

namespace {

	if ( ! class_exists( 'WC_Data_Store' ) ) {

		class WC_Data_Store {
			public static function load( $type ) {
				return new class() {
					public function get_notes_with_name( $name ) {
					return isset( \Automattic\WooCommerce\Admin\Notes\Note::$by_name[ $name ] ) ? [ $name ] : [];
					}
				};
			}
		}
	}
}
