<?php
/**
 * The lifecycle of a marketplace that sends a reset card again at once, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Flows\Releases_Reset_Imports;

/**
 * {@see Scripted_Article_Lifecycle} whose reset cards leave their open import tasks.
 */
final class Scripted_Releasing_Lifecycle extends Scripted_Article_Lifecycle implements Releases_Reset_Imports {}
