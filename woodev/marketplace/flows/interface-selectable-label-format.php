<?php
/**
 * Labels whose look the merchant chooses.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * An optional {@see Label_Provider} of a marketplace whose labels depend on a setting of the account, such as the sticker size and
 * the file type. A stored document is handed over only while the account still asks for the format it was made in; after a change
 * the next click makes the labels anew.
 *
 * @since 2.1.28
 */
interface Selectable_Label_Format extends Label_Provider {
	/**
	 * Names the format the account's labels are made in now.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return string Any text that changes whenever the labels would look different, for example `png-58x40`. A local read: no
	 *                remote call.
	 */
	public function label_format( Account $account ): string;
}
