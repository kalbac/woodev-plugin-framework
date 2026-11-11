<?php
/**
 * What the fake marketplace's calls throw.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Jobs\Remote_Error;

/**
 * The fake marketplace's own exception: a refused call. It carries the classification its adapter would work out from the
 * answer, so a test says «the request was throttled for 40 s» in one line. {@see Fake_Error_Classifier} is the only reader.
 */
final class Fake_Remote_Exception extends \RuntimeException {
	/**
	 * The classification.
	 *
	 * @var Remote_Error
	 */
	private $error;

	/**
	 * Creates the exception.
	 *
	 * @param Remote_Error $error The classification.
	 */
	public function __construct( Remote_Error $error ) {
		parent::__construct( $error->describe() );

		$this->error = $error;
	}

	/**
	 * Builds a refused call.
	 *
	 * @param string   $consequence A `Remote_Error` consequence.
	 * @param string   $message     The marketplace's text.
	 * @param int|null $retry_after Seconds the marketplace asked to wait.
	 * @param bool     $sent        Whether the request left the site.
	 * @return self
	 */
	public static function of( string $consequence, string $message = 'refused', ?int $retry_after = null, bool $sent = true ): self {
		return new self( new Remote_Error( $consequence, 'fake_' . $consequence, $message, $retry_after, $sent ) );
	}

	/**
	 * Returns the classification.
	 *
	 * @return Remote_Error
	 */
	public function error(): Remote_Error {
		return $this->error;
	}
}
