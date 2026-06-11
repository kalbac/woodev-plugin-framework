<?php
/**
 * Unit-test stand-in for wp-admin/includes/plugin.php.
 *
 * The unit suite defines ABSPATH as the tests/ directory (tests/bootstrap.php), so
 * production code doing `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
 * resolves to THIS file instead of real WordPress. It deliberately defines NOTHING:
 * unit tests stub is_plugin_active() / is_plugin_active_for_network() /
 * deactivate_plugins() through Brain Monkey, and an unstubbed call must keep failing
 * loudly ("not defined nor mocked") rather than hitting a silent fixture fallback.
 *
 * Tests can assert the require actually fired via get_included_files()
 * (see LicenseCommandDeactivateTest::test_partial_plugin_api_triggers_require).
 *
 * @package Woodev\Tests
 */
