<?php
/**
 * The «Сопоставления» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the mappings the way the page's old list did: the editor's summaries, the profile and shop-category names, and the attribute
 * counts from the fields the catalog cache already holds (it never asks the marketplace for them).
 *
 * @since 2.1.13
 */
final class Mapping_List_Provider extends List_Provider {
	/**
	 * Mappings.
	 *
	 * @var Mapping_Editor
	 */
	private $mappings;

	/**
	 * Profiles, for the names in the «Профиль» column.
	 *
	 * @var Profile_Editor
	 */
	private $profiles;

	/**
	 * Merchant-facing marketplace name.
	 *
	 * @var string
	 */
	private $marketplace;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.13
	 * @since 2.1.23 Takes the marketplace name.
	 *
	 * @param string         $page_url    Address of the page.
	 * @param Mapping_Editor $mappings    Mappings.
	 * @param Profile_Editor $profiles    Profiles.
	 * @param string         $marketplace Merchant-facing marketplace name.
	 */
	public function __construct( string $page_url, Mapping_Editor $mappings, Profile_Editor $profiles, string $marketplace ) {
		parent::__construct( $page_url );

		$this->mappings    = $mappings;
		$this->profiles    = $profiles;
		$this->marketplace = $marketplace;
	}

	/**
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function get_id(): string {
		return Mapping_List_Definition::TABLE;
	}

	/**
	 * @since 2.1.13
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		return Mapping_List_Definition::build( $this->marketplace );
	}

	/**
	 * @return array<int,array{id:string,url:string,cells:array<string,mixed>,sort:array<string,string|int>}>
	 */
	protected function records(): array {
		$counts   = $this->mappings->attribute_counts();
		$profiles = $this->profile_names();
		$shop     = $this->shop_labels();
		$records  = [];

		foreach ( $this->mappings->summaries() as $mapping ) {
			$id      = (int) $mapping['id'];
			$url     = $this->form_url( [ 'mapping' => (string) $id ] );
			$profile = $profiles[ (int) $mapping['profile_id'] ] ?? '';
			$terms   = implode(
				', ',
				array_filter(
					array_map(
						static function ( int $term ) use ( $shop ): string {
							return $shop[ $term ] ?? '';
						},
						$mapping['terms']
					)
				)
			);
			$records[] = [
				'id'    => (string) $id,
				'url'   => $url,
				'cells' => [
					'name'       => [
						'label' => $mapping['name'],
						'url'   => $url,
					],
					'category'   => '' === $mapping['category_label'] ? __( 'Не выбрана', 'woodev-plugin-framework' ) : $mapping['category_label'],
					'shop'       => '' === $terms ? '—' : $terms,
					'profile'    => '' === $profile ? null : [
						'label' => $profile,
						'url'   => $this->form_url(
							[
								'tab'     => 'profiles',
								'profile' => (string) $mapping['profile_id'],
							]
						),
					],
					'attributes' => self::attributes_cell( $counts[ $id ] ?? null ),
					'products'   => (int) $mapping['products'],
				],
				'sort'  => [
					'name'       => $mapping['name'],
					'category'   => $mapping['category_label'],
					'shop'       => $terms,
					'profile'    => $profile,
					'attributes' => (int) ( $counts[ $id ]['filled'] ?? 0 ),
					'products'   => (int) $mapping['products'],
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
		return Russian_Plural::pick( $total, __( 'сопоставление', 'woodev-plugin-framework' ), __( 'сопоставления', 'woodev-plugin-framework' ), __( 'сопоставлений', 'woodev-plugin-framework' ) );
	}

	/**
	 * A shop with no product categories cannot have a mapping: the empty list says so instead of offering a first mapping.
	 *
	 * @param int $total Number of records.
	 * @return array<string,string>
	 */
	protected function messages( int $total ): array {
		if ( $total > 0 || [] !== $this->mappings->shop_categories() ) {
			return [];
		}

		return [ 'empty' => __( 'В магазине пока нет категорий товаров, а сопоставление работает по категориям. Создайте категорию товаров и вернитесь сюда.', 'woodev-plugin-framework' ) ];
	}

	/**
	 * «3 из 12» as a badge: orange with a «!» when a required attribute of the category is still empty, green otherwise. A mapping whose
	 * category fields are not cached is a dash that says how to get them: the form loads them.
	 *
	 * @param array{filled:int,total:int,required_missing:int}|null $counts The mapping's counts; null when the fields are not cached.
	 * @return array{label:string,tone:string,tooltip:string}
	 */
	private static function attributes_cell( ?array $counts ): array {
		if ( null === $counts ) {
			return [
				'label'   => '—',
				'tone'    => 'neutral',
				'tooltip' => __( 'Откройте сопоставление, чтобы загрузить характеристики.', 'woodev-plugin-framework' ),
			];
		}

		/* translators: 1: attributes the mapping fills, 2: attributes its category has. */
		$label   = sprintf( __( '%1$d из %2$d', 'woodev-plugin-framework' ), $counts['filled'], $counts['total'] );
		$missing = $counts['required_missing'];

		if ( 0 === $missing ) {
			return [
				'label'   => $label,
				'tone'    => 'success',
				'tooltip' => '',
			];
		}

		return [
			'label'   => $label . ' !',
			'tone'    => 'warning',
			/* translators: %d: number of required attributes left empty. */
			'tooltip' => sprintf( __( 'Не заполнены обязательные атрибуты: %d', 'woodev-plugin-framework' ), $missing ),
		];
	}

	/**
	 * @return array<int,string> Profile names by profile id.
	 */
	private function profile_names(): array {
		$names = [];

		foreach ( $this->profiles->summaries() as $profile ) {
			$names[ $profile['id'] ] = $profile['name'];
		}

		return $names;
	}

	/**
	 * @return array<int,string> Shop category names by term id.
	 */
	private function shop_labels(): array {
		$labels = [];

		foreach ( $this->mappings->shop_categories() as $category ) {
			$labels[ $category['id'] ] = $category['label'];
		}

		return $labels;
	}
}
