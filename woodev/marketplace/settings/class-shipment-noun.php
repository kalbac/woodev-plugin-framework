<?php
/**
 * Marketplace settings — the words the shipment screens use.
 *
 * @package Woodev\Framework\Marketplace\Settings
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The nouns of a marketplace's shipments and cargo places in every form the framework's Russian sentences need. They are
 * «отгрузка» and «грузовое место» unless the marketplace gives its own through {@see Shipment_Wording}.
 *
 * The shipment noun must be feminine and inanimate («поставка», «отгрузка»): the sentences around it say «новая», «эта» and
 * «передана». The cargo place noun must be neuter and inanimate («грузовое место»).
 *
 * Every form is given in lower case, except the title; {@see Posting_Noun::capitalised()} starts a sentence with one.
 *
 * @since 2.1.28
 */
final class Shipment_Noun {
	/**
	 * The forms a noun must give.
	 */
	private const FORMS = [ 'title', 'one', 'of_one', 'to_one', 'acc', 'many', 'of_many', 'place', 'places', 'of_places' ];

	/**
	 * Words by form.
	 *
	 * @var array<string,string>
	 */
	private $forms;

	/**
	 * Creates the nouns.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string> $forms Translated words, one per form; for «поставка»:
	 *                                    `title` «Поставки» (the page and its menu entry, capitalised),
	 *                                    `one` «поставка» (nominative singular),
	 *                                    `of_one` «поставки» (genitive singular, also after «2», «3», «4»),
	 *                                    `to_one` «поставке» (dative and prepositional singular),
	 *                                    `acc` «поставку» (accusative singular),
	 *                                    `many` «поставки» (nominative plural),
	 *                                    `of_many` «поставок» (genitive plural),
	 *                                    `place` «грузовое место» (singular, nominative and accusative),
	 *                                    `places` «грузовые места» (nominative plural),
	 *                                    `of_places` «грузовых мест» (genitive plural).
	 * @throws \InvalidArgumentException When a form is missing or empty.
	 */
	public function __construct( array $forms ) {
		foreach ( self::FORMS as $form ) {
			if ( '' === trim( (string) ( $forms[ $form ] ?? '' ) ) ) {
				throw new \InvalidArgumentException( 'The shipment noun has no form "' . $form . '".' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A constant of this class.
			}
		}

		$this->forms = $forms;
	}

	/**
	 * Returns the words of a marketplace: the descriptor's own ones, or the default.
	 *
	 * @since 2.1.28
	 *
	 * @param Settings_Descriptor $descriptor The marketplace's descriptor.
	 * @return self
	 */
	public static function for_descriptor( Settings_Descriptor $descriptor ): self {
		return $descriptor instanceof Shipment_Wording ? $descriptor->shipment_noun() : self::dispatch();
	}

	/**
	 * Returns «отгрузка», the noun of a marketplace that names none.
	 *
	 * @since 2.1.28
	 *
	 * @return self
	 */
	public static function dispatch(): self {
		return new self(
			[
				'title'     => _x( 'Отгрузки', 'shipment noun: title', 'woodev-plugin-framework' ),
				'one'       => _x( 'отгрузка', 'shipment noun: one', 'woodev-plugin-framework' ),
				'of_one'    => _x( 'отгрузки', 'shipment noun: of one', 'woodev-plugin-framework' ),
				'to_one'    => _x( 'отгрузке', 'shipment noun: to one', 'woodev-plugin-framework' ),
				'acc'       => _x( 'отгрузку', 'shipment noun: accusative', 'woodev-plugin-framework' ),
				'many'      => _x( 'отгрузки', 'shipment noun: many', 'woodev-plugin-framework' ),
				'of_many'   => _x( 'отгрузок', 'shipment noun: of many', 'woodev-plugin-framework' ),
				'place'     => _x( 'грузовое место', 'shipment noun: cargo place', 'woodev-plugin-framework' ),
				'places'    => _x( 'грузовые места', 'shipment noun: cargo places', 'woodev-plugin-framework' ),
				'of_places' => _x( 'грузовых мест', 'shipment noun: of cargo places', 'woodev-plugin-framework' ),
			]
		);
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The page title and menu entry.
	 */
	public function title(): string {
		return $this->forms['title'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Nominative singular.
	 */
	public function one(): string {
		return $this->forms['one'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Genitive singular.
	 */
	public function of_one(): string {
		return $this->forms['of_one'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Dative and prepositional singular.
	 */
	public function to_one(): string {
		return $this->forms['to_one'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Accusative singular.
	 */
	public function acc(): string {
		return $this->forms['acc'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Nominative plural.
	 */
	public function many(): string {
		return $this->forms['many'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Genitive plural.
	 */
	public function of_many(): string {
		return $this->forms['of_many'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string A cargo place.
	 */
	public function place(): string {
		return $this->forms['place'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Cargo places.
	 */
	public function places(): string {
		return $this->forms['places'];
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string «Of cargo places», for a count of many.
	 */
	public function of_places(): string {
		return $this->forms['of_places'];
	}
}
