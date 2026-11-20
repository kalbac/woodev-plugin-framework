<?php
/**
 * The «Профили» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the profiles the way the page's old list did: the editor's summaries, with what each profile sets (the values included) and
 * the mappings that choose it.
 *
 * @since 2.1.13
 */
final class Profile_List_Provider extends List_Provider {
	/**
	 * Separator of the lines of «Свойства профиля».
	 */
	private const PROPERTY_SEPARATOR = ' · ';

	/**
	 * Profiles.
	 *
	 * @var Profile_Editor
	 */
	private $profiles;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.13
	 *
	 * @param string         $page_url Address of the page.
	 * @param Profile_Editor $profiles Profiles.
	 */
	public function __construct( string $page_url, Profile_Editor $profiles ) {
		parent::__construct( $page_url );

		$this->profiles = $profiles;
	}

	/**
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function get_id(): string {
		return Profile_List_Definition::TABLE;
	}

	/**
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		return Profile_List_Definition::build();
	}

	/**
	 * @return array<int,array{id:string,url:string,cells:array<string,mixed>,sort:array<string,string|int>}>
	 */
	protected function records(): array {
		$records = [];

		foreach ( $this->profiles->summaries() as $profile ) {
			$url        = $this->form_url(
				[
					'tab'     => 'profiles',
					'profile' => (string) $profile['id'],
				]
			);
			$properties = implode( self::PROPERTY_SEPARATOR, $profile['properties'] );
			$used_in    = implode( ', ', $profile['used_in'] );
			$records[]  = [
				'id'    => (string) $profile['id'],
				'url'   => $url,
				'cells' => [
					'name'       => [
						'label' => $profile['name'],
						'url'   => $url,
					],
					'properties' => '' === $properties ? __( 'Не настроен', 'woodev-plugin-framework' ) : $properties,
					'used_in'    => '' === $used_in ? '—' : $used_in,
				],
				'sort'  => [
					'name'       => $profile['name'],
					'properties' => $properties,
					'used_in'    => $used_in,
				],
			];
		}

		return $records;
	}

	/**
	 * @param int $total Number of records.
	 * @return string
	 */
	protected function noun( int $total ): string {
		return Russian_Plural::pick( $total, __( 'профиль', 'woodev-plugin-framework' ), __( 'профиля', 'woodev-plugin-framework' ), __( 'профилей', 'woodev-plugin-framework' ) );
	}
}
