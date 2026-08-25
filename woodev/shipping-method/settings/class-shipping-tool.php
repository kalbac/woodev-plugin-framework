<?php
/**
 * Woodev Shipping Tool
 *
 * Descriptor for one entry in the «Инструменты» section (issue #505). Mirrors
 * WooCommerce's own `woocommerce_debug_tools` shape key-for-key (spec D1:
 * `name` / `desc` / `button` / `callback` / `disabled` / `status_text` /
 * `selector`) so a plugin author who already knows that filter recognises this
 * one on sight, and a future bridge onto WC's own tools page stays a small
 * adapter rather than a redesign.
 *
 * `selector` deliberately differs from WC's own (`description` / `class` /
 * `name` / `placeholder` / `search_action`, feeding an AJAX select2): our tools
 * choose among a short, server-known list, so ours is a STATIC option list —
 * `description` / `name` / `placeholder` / `options` (`{value,label}[]`) /
 * `default` — and has no `search_action`. A future WC bridge needs to know
 * exactly this difference, which is why it is spelled out here rather than
 * left to be discovered.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Shipping_Tool' ) ) :

	/**
	 * Immutable descriptor for one registered tool.
	 *
	 * Built only via {@see self::create()}. Never serialize {@see self::$callback}
	 * to the browser — {@see self::to_array()} deliberately omits it.
	 *
	 * @since 2.0.2
	 */
	final class Shipping_Tool {

		/** @var string tool id, unique within the registry. */
		private string $id;

		/** @var string tool title. */
		private string $name;

		/** @var string what the tool does, shown under the title. */
		private string $desc;

		/** @var string the button's label. */
		private string $button;

		/**
		 * What runs when the tool's button is pressed.
		 *
		 * @var callable(array<string,mixed>):Tool_Result
		 */
		private $callback;

		/** @var bool whether the control renders but the action is refused. */
		private bool $disabled;

		/** @var string why the tool is disabled, or a live status line. */
		private string $status_text;

		/**
		 * An optional input rendered BEFORE the button. Null when the tool takes
		 * no input.
		 *
		 * @var array{description?:string,name:string,placeholder?:string,options:array<int,array{value:string,label:string}>,default?:string}|null
		 */
		private ?array $selector;

		/**
		 * Use {@see self::create()} instead.
		 *
		 * @since 2.0.2
		 */
		private function __construct(
			string $id,
			string $name,
			string $desc,
			string $button,
			callable $callback,
			bool $disabled,
			string $status_text,
			?array $selector
		) {
			$this->id          = $id;
			$this->name        = $name;
			$this->desc        = $desc;
			$this->button      = $button;
			$this->callback    = $callback;
			$this->disabled    = $disabled;
			$this->status_text = $status_text;
			$this->selector    = $selector;
		}

		/**
		 * Builds a tool descriptor.
		 *
		 * @since 2.0.2
		 *
		 * @param string                                                                                                                              $id           tool id, unique within the registry.
		 * @param string                                                                                                                              $name         tool title.
		 * @param string                                                                                                                              $desc         what the tool does.
		 * @param string                                                                                                                              $button       the button's label.
		 * @param callable(array<string,mixed>):Tool_Result                                                                                           $callback     what runs on click; receives the selector values keyed by selector `name`.
		 * @param bool                                                                                                                                $disabled     whether the control renders but the action is refused.
		 * @param string                                                                                                                              $status_text  why disabled, or a live status line.
		 * @param array{description?:string,name:string,placeholder?:string,options:array<int,array{value:string,label:string}>,default?:string}|null $selector an optional input rendered before the button.
		 *
		 * @return self
		 */
		public static function create(
			string $id,
			string $name,
			string $desc,
			string $button,
			callable $callback,
			bool $disabled = false,
			string $status_text = '',
			?array $selector = null
		): self {
			return new self( $id, $name, $desc, $button, $callback, $disabled, $status_text, $selector );
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * @since 2.0.2
		 * @return callable(array<string,mixed>):Tool_Result
		 */
		public function get_callback(): callable {
			return $this->callback;
		}

		/**
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_disabled(): bool {
			return $this->disabled;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_status_text(): string {
			return $this->status_text;
		}

		/**
		 * The selector field names this tool's callback expects — used to
		 * allow-list incoming REST args to exactly what this tool declared.
		 *
		 * @since 2.0.2
		 * @return string[]
		 */
		public function get_selector_names(): array {
			if ( null === $this->selector ) {
				return [];
			}

			return [ $this->selector['name'] ];
		}

		/**
		 * Descriptor for the client — the callback is NEVER included.
		 *
		 * @since 2.0.2
		 * @return array{id:string,name:string,desc:string,button:string,disabled:bool,status_text:string,selector?:array<string,mixed>}
		 */
		public function to_array(): array {
			$data = [
				'id'          => $this->id,
				'name'        => $this->name,
				'desc'        => $this->desc,
				'button'      => $this->button,
				'disabled'    => $this->disabled,
				'status_text' => $this->status_text,
			];

			if ( null !== $this->selector ) {
				$data['selector'] = $this->selector;
			}

			return $data;
		}
	}

endif;
