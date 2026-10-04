<?php
/**
 * Fixture code OUTSIDE any registered plugin directory (#130 subprocess tests).
 */

function foreign_fail() {
	throw new \RuntimeException( 'foreign failure' );
}
