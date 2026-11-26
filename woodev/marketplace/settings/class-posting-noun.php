<?php
/**
 * The merchant's word for one unit of a marketplace's order work.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The noun the framework's order screens call a posting by, in every form their Russian sentences need. It is «отправление» unless
 * the marketplace gives its own through {@see Posting_Wording} on its settings descriptor.
 *
 * The noun must be neuter and inanimate («отправление», «сборочное задание»): the sentences around it say «это», «оно» and
 * «отменено», and use the {@see self::one()} form where Russian asks for the accusative. A masculine or feminine word reads wrong.
 *
 * Every form is given in lower case; {@see self::capitalised()} starts a sentence or a column title with one.
 *
 * @since 2.1.23
 */
final class Posting_Noun {
	/**
	 * The forms a noun must give.
	 */
	private const FORMS = [ 'one', 'of_one', 'to_one', 'with_one', 'many', 'of_many', 'about_many' ];

	/**
	 * Words by form.
	 *
	 * @var array<string,string>
	 */
	private $forms;

	/**
	 * Creates the noun.
	 *
	 * @since 2.1.23
	 *
	 * @param array<string,string> $forms Translated words, one per form; for «отправление»:
	 *                                    `one` «отправление» (nominative and accusative singular),
	 *                                    `of_one` «отправления» (genitive singular),
	 *                                    `to_one` «отправлению» (dative singular, as in «поиск по …»),
	 *                                    `with_one` «с отправлением» (with the preposition: «со сборочным заданием»),
	 *                                    `many` «отправления» (nominative plural),
	 *                                    `of_many` «отправлений» (genitive plural),
	 *                                    `about_many` «об отправлениях» (with the preposition: «о сборочных заданиях»).
	 * @throws \InvalidArgumentException When a form is missing or empty.
	 */
	public function __construct( array $forms ) {
		foreach ( self::FORMS as $form ) {
			if ( '' === trim( (string) ( $forms[ $form ] ?? '' ) ) ) {
				throw new \InvalidArgumentException( 'The posting noun has no form "' . $form . '".' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A constant of this class.
			}
		}

		$this->forms = $forms;
	}

	/**
	 * Returns the noun of a marketplace: the descriptor's own one, or «отправление».
	 *
	 * @since 2.1.23
	 *
	 * @param Settings_Descriptor $descriptor The marketplace's descriptor.
	 * @return self
	 */
	public static function for_descriptor( Settings_Descriptor $descriptor ): self {
		return $descriptor instanceof Posting_Wording ? $descriptor->posting_noun() : self::shipment();
	}

	/**
	 * Returns «отправление», the noun of a marketplace that names none.
	 *
	 * @since 2.1.23
	 *
	 * @return self
	 */
	public static function shipment(): self {
		return new self(
			[
				'one'        => _x( 'отправление', 'posting noun: one', 'woodev-plugin-framework' ),
				'of_one'     => _x( 'отправления', 'posting noun: of one', 'woodev-plugin-framework' ),
				'to_one'     => _x( 'отправлению', 'posting noun: to one', 'woodev-plugin-framework' ),
				'with_one'   => _x( 'с отправлением', 'posting noun: with one', 'woodev-plugin-framework' ),
				'many'       => _x( 'отправления', 'posting noun: many', 'woodev-plugin-framework' ),
				'of_many'    => _x( 'отправлений', 'posting noun: of many', 'woodev-plugin-framework' ),
				'about_many' => _x( 'об отправлениях', 'posting noun: about many', 'woodev-plugin-framework' ),
			]
		);
	}

	/**
	 * Returns a word or a sentence with its first letter in upper case.
	 *
	 * @since 2.1.23
	 *
	 * @param string $text Text that starts with a form of the noun.
	 * @return string
	 */
	public static function capitalised( string $text ): string {
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * Returns the nominative (and accusative) singular.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function one(): string {
		return $this->forms['one'];
	}

	/**
	 * Returns the genitive singular.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function of_one(): string {
		return $this->forms['of_one'];
	}

	/**
	 * Returns the dative singular.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function to_one(): string {
		return $this->forms['to_one'];
	}

	/**
	 * Returns «with one», the preposition included.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function with_one(): string {
		return $this->forms['with_one'];
	}

	/**
	 * Returns the nominative plural.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function many(): string {
		return $this->forms['many'];
	}

	/**
	 * Returns the genitive plural.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function of_many(): string {
		return $this->forms['of_many'];
	}

	/**
	 * Returns «about several», the preposition included.
	 *
	 * @since 2.1.23
	 *
	 * @return string
	 */
	public function about_many(): string {
		return $this->forms['about_many'];
	}
}
