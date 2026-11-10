<?php
/**
 * Reads and writes export profiles for the editor view.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Price\Price_Source;

defined( 'ABSPATH' ) || exit;

/**
 * The profile half of the editor, with no HTTP in it: the list, one profile as form values, and the guarded writes. A write
 * result is `{status, id, errors}`; the controller maps the status to HTTP.
 *
 * @since 2.0.30
 */
final class Profile_Editor {
	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * Mappings, for the «used by» count.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * What the form offers.
	 *
	 * @var Profile_Choices
	 */
	private $choices;

	/**
	 * The marketplace's price source for its own currency rules; null when it has none.
	 *
	 * @var Price_Source|null
	 */
	private $price_source;

	/**
	 * Creates the editor.
	 *
	 * @since 2.0.30
	 *
	 * @param Wpdb_Profile_Store $profiles     Profiles.
	 * @param Wpdb_Mapping_Store $mappings     Mappings.
	 * @param Profile_Choices    $choices      What the form offers.
	 * @param Price_Source|null  $price_source Price source of the marketplace.
	 */
	public function __construct( Wpdb_Profile_Store $profiles, Wpdb_Mapping_Store $mappings, Profile_Choices $choices, ?Price_Source $price_source ) {
		$this->profiles     = $profiles;
		$this->mappings     = $mappings;
		$this->choices      = $choices;
		$this->price_source = $price_source;
	}

	/**
	 * Lists the profiles for the list.
	 *
	 * @since 2.0.30
	 *
	 * @return array<int,array{id:int,name:string,mappings:int}>
	 */
	public function summaries(): array {
		$rows = [];

		foreach ( $this->profiles->all() as $profile ) {
			$id     = (int) $profile->id();
			$rows[] = [
				'id'       => $id,
				'name'     => $profile->name(),
				'mappings' => $this->mappings->count_using_profile( $id ),
			];
		}

		return $rows;
	}

	/**
	 * Returns one profile as the values of the form.
	 *
	 * @since 2.0.30
	 *
	 * @param int $profile_id Profile id.
	 * @return array<string,mixed>|null
	 */
	public function detail( int $profile_id ): ?array {
		$profile = $this->profiles->get( $profile_id );

		if ( null === $profile ) {
			return null;
		}

		return [
			'id'     => $profile_id,
			'name'   => $profile->name(),
			'values' => $this->form_values( $profile->settings() ),
		];
	}

	/**
	 * Saves a profile: creates it when `$profile_id` is null, else changes it.
	 *
	 * @since 2.0.30
	 *
	 * @param int|null            $profile_id Profile id, null for a new one.
	 * @param array<string,mixed> $body       Decoded request body.
	 * @return array{status:string,id:int,errors:string[]} Status `saved`, `invalid`, `missing` or `failed`.
	 */
	public function save( ?int $profile_id, array $body ): array {
		$stored = null === $profile_id ? null : $this->profiles->get( $profile_id );

		if ( null !== $profile_id && null === $stored ) {
			return self::result( 'missing', 0, [ Mapping_Messages::profile_not_found() ] );
		}

		$input = Profile_Input::from_request( $body, $this->choices, $this->price_source, null === $stored ? [] : $stored->settings() );

		if ( ! $input->is_valid() ) {
			return self::result( 'invalid', 0, $input->errors() );
		}

		try {
			$id = null === $profile_id ? $this->profiles->create( $input->name(), $input->settings() ) : $profile_id;

			if ( null !== $profile_id && ! $this->profiles->update( $id, $input->name(), $input->settings() ) ) {
				return self::result( 'failed', 0, [ Mapping_Messages::save_failed() ] );
			}
		} catch ( \RuntimeException $e ) {
			return self::result( 'failed', 0, [ Mapping_Messages::save_failed() ] );
		}

		return self::result( 'saved', $id );
	}

	/**
	 * Deletes a profile unless a mapping uses it.
	 *
	 * @since 2.0.30
	 *
	 * @param int $profile_id Profile id.
	 * @return array{status:string,id:int,errors:string[]} Status `saved` (deleted), `missing` or `in_use`.
	 */
	public function delete( int $profile_id ): array {
		$used = $this->mappings->count_using_profile( $profile_id );

		if ( $used > 0 ) {
			return self::result( 'in_use', $profile_id, [ Mapping_Messages::profile_in_use( $used ) ] );
		}

		if ( ! $this->profiles->delete( $profile_id ) ) {
			return self::result( 'missing', 0, [ Mapping_Messages::profile_not_found() ] );
		}

		return self::result( 'saved', $profile_id );
	}

	/**
	 * Turns stored settings into the flat values the form posts back.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @return array<string,mixed>
	 */
	private function form_values( array $settings ): array {
		$values = $this->source_values( $settings );

		foreach ( [ 'fallback_width', 'fallback_height', 'fallback_depth', 'fallback_weight' ] as $key ) {
			$values[ $key ] = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		}

		$units                    = isset( $settings['units'] ) && is_array( $settings['units'] ) ? $settings['units'] : [];
		$values['unit_weight']    = (string) ( $units['weight'] ?? '' );
		$values['unit_dimension'] = (string) ( $units['dimension'] ?? '' );
		$values['vat_rate']       = (string) ( $settings['vat_rate'] ?? '' );

		$currency = self::currency_values( (string) ( $settings['currency_rule'] ?? '' ) );
		$price    = self::price_values( $settings['price_rule'] ?? null );

		return array_merge( $values, $currency, $price );
	}

	/**
	 * Splits each stored source value into the kind and key fields of the form.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @return array<string,string>
	 */
	private function source_values( array $settings ): array {
		$values = [];

		foreach ( $this->choices->settings() as $setting ) {
			$has_value                   = isset( $settings[ $setting ] ) && is_string( $settings[ $setting ] );
			$stored                      = $has_value ? $settings[ $setting ] : $this->choices->default_for( $setting );
			list( $kind, $key )          = Profile_Choices::split( $stored );
			$values[ $setting ]          = $kind;
			$values[ $setting . '_key' ] = $key;
		}

		return $values;
	}

	/**
	 * Splits a stored currency rule into the fields of the form.
	 *
	 * @param string $rule Stored rule: '', `rate:N`, `field:KEY` or `fixed:CODE`.
	 * @return array<string,string>
	 */
	private static function currency_values( string $rule ): array {
		$values = [
			'currency_kind'  => Profile_Input::CURRENCY_ACCOUNT,
			'currency_rate'  => '',
			'currency_field' => '',
			'currency_code'  => '',
		];

		list( $kind, $rest ) = Profile_Choices::split( $rule );

		if ( 'rate' === $kind ) {
			return array_merge(
				$values,
				[
					'currency_kind' => 'rate',
					'currency_rate' => $rest,
				]
			);
		}

		if ( 'field' === $kind ) {
			return array_merge(
				$values,
				[
					'currency_kind'  => 'field',
					'currency_field' => $rest,
				]
			);
		}

		return 'fixed' === $kind ? array_merge(
			$values,
			[
				'currency_kind' => 'fixed',
				'currency_code' => $rest,
			]
		) : $values;
	}

	/**
	 * Splits a stored price rule into the fields of the form.
	 *
	 * @param mixed $rule Stored `price_rule`.
	 * @return array<string,mixed>
	 */
	private static function price_values( $rule ): array {
		$rule = is_array( $rule ) ? $rule : [];

		return [
			'price_override'       => [] !== $rule,
			'price_base'           => (string) ( $rule['base'] ?? '' ),
			'price_markup_kind'    => (string) ( $rule['markup_kind'] ?? '' ),
			'price_markup'         => (string) ( $rule['markup'] ?? '' ),
			'price_rounding'       => (string) ( $rule['rounding'] ?? '' ),
			'price_old_price_rule' => (string) ( $rule['old_price_rule'] ?? '' ),
			'price_min_percent'    => (string) ( $rule['min_percent'] ?? '' ),
		];
	}

	/**
	 * Builds a write result.
	 *
	 * @param string   $status Status.
	 * @param int      $id     Id of the written or deleted profile, 0 when none.
	 * @param string[] $errors Error lines.
	 * @return array{status:string,id:int,errors:string[]}
	 */
	private static function result( string $status, int $id, array $errors = [] ): array {
		return [
			'status' => $status,
			'id'     => $id,
			'errors' => $errors,
		];
	}
}
