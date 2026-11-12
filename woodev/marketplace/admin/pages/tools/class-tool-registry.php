<?php
/**
 * The tools a marketplace plugin offers on «Инструменты».
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the tools of one page by id. A plugin supplies them through the `woodev_{adapter id}_marketplace_tools` filter,
 * the same way it supplies the mapping choices; the framework drops anything that is not a {@see Tool} and keeps the
 * first of two tools with the same id.
 *
 * @since 2.1.5
 */
final class Tool_Registry {
	/**
	 * Tools by id, in the order the plugin gave them.
	 *
	 * @var array<string,Tool>
	 */
	private $tools = [];

	/**
	 * Builds the registry from the filter of a marketplace adapter.
	 *
	 * @since 2.1.5
	 *
	 * @param string $adapter_id Adapter id (the filter name is built from it).
	 * @return self
	 */
	public static function for_adapter( string $adapter_id ): self {
		$supplied = apply_filters( 'woodev_' . $adapter_id . '_marketplace_tools', [] );

		return new self( is_array( $supplied ) ? $supplied : [] );
	}

	/**
	 * @since 2.1.5
	 *
	 * @param array<int,mixed> $tools Candidates; anything that is not a {@see Tool} is ignored.
	 */
	public function __construct( array $tools ) {
		foreach ( $tools as $tool ) {
			if ( $tool instanceof Tool && ! isset( $this->tools[ $tool->id() ] ) ) {
				$this->tools[ $tool->id() ] = $tool;
			}
		}
	}

	/**
	 * All tools, by id.
	 *
	 * @since 2.1.5
	 *
	 * @return array<string,Tool>
	 */
	public function all(): array {
		return $this->tools;
	}

	/**
	 * One tool by id.
	 *
	 * @since 2.1.5
	 *
	 * @param string $id Tool id.
	 * @return Tool|null Null when the page has no such tool.
	 */
	public function get( string $id ): ?Tool {
		return $this->tools[ $id ] ?? null;
	}
}
