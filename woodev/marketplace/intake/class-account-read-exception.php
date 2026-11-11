<?php
/**
 * A failed account read.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * The database refused to answer a question about accounts. Distinct from an answer of «no such account», because the intake
 * must retry the first and may file the second as unknown.
 *
 * @since 2.1.0
 */
final class Account_Read_Exception extends \RuntimeException {
}
