<?php
/**
 * Labels answered at once, in a format the merchant chooses.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Selectable_Label_Format;

/**
 * {@see Fake_Immediate_Label_Provider} whose format is `$format`: the setting a test changes.
 */
final class Fake_Immediate_Formatted_Label_Provider extends Fake_Immediate_Label_Provider implements Selectable_Label_Format {
	/**
	 * The format of the account.
	 *
	 * @var string
	 */
	public $format = 'png-58x40';

	/**
	 * Returns the format.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	public function label_format( Account $account ): string {
		return $this->format;
	}
}
