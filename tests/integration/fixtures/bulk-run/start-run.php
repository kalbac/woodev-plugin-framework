<?php
/**
 * Starts a bulk run on the «Товары сайта» table the way the REST apply does (#1795, E2 scale test).
 *
 * Usage: `wp eval-file start-run.php <plugin id> <account id> <action> [warehouse id] [state filter]`
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Woodev\Framework\Admin\Records\Records_Registry;

$plugin_id = isset( $args[0] ) ? (string) $args[0] : '';
$account   = isset( $args[1] ) ? (int) $args[1] : 0;
$action    = isset( $args[2] ) ? (string) $args[2] : '';
$warehouse = isset( $args[3] ) ? (string) $args[3] : '0';
$state     = isset( $args[4] ) ? (string) $args[4] : '';

if ( '' === $plugin_id || $account <= 0 || '' === $action ) {
	WP_CLI::error( 'Usage: wp eval-file start-run.php <plugin id> <account id> <action> [warehouse id] [state filter]' );
}

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

// The provider is registered when the REST API starts.
rest_get_server();

$registration = Records_Registry::instance()->get( $plugin_id, 'products' );

if ( null === $registration ) {
	WP_CLI::error( 'No «products» table is registered for ' . $plugin_id . '.' );
}

$provider = $registration->get_provider();
$context  = [ 'account_id' => (string) $account ];
$filters  = '' === $state ? [] : [ 'state' => $state ];
$query    = [ 'search' => '', 'filters' => $filters ];
$payload  = 'set_warehouse' === $action ? [ 'warehouse' => $warehouse ] : [];

$preview = $provider->preview_matching( $context, $action, $query, $payload );
WP_CLI::log( 'Preview: ' . $preview['requested'] . ' products. ' . $preview['scope_sentence'] );

$answer = $provider->apply_matching( $context, $action, $query, $payload );
WP_CLI::success( 'Run ' . $answer['run']['id'] . ' started for ' . $answer['run']['products_total'] . ' products.' );
