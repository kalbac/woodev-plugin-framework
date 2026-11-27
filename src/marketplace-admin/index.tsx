/**
 * Marketplace admin entry: adds a route for every page of every marketplace menu to WooCommerce's own admin app through
 * `woocommerce_admin_pages_list`. One bundle serves all active marketplace plugins; PHP (`Admin_Collector`) enqueues it
 * once, with a manifest that lists every menu.
 *
 * Each `path` equals what PHP registered with `wc_admin_register_page()` (`Menu::get_page_path()`), because PHP wrote both.
 *
 * @package woodev-plugin-framework
 */

import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import { addFilter } from '@wordpress/hooks';
import { nameForeignAnswers } from '../components/foreign-answer';
import { readManifest } from './manifest';
import { buildAdminPages } from './pages';
import type { WcAdminPage } from './types';
import './views/diagnostics';
import './views/mappings';
import './views/records';
import './views/tools';

// Every request of the marketplace screens, their embedded settings included: a replaced answer names its HTTP status.
apiFetch.use( nameForeignAnswers( /woodev\/v1\/admin\/(records|marketplace)\// ) );

addFilter( 'woocommerce_admin_pages_list', 'woodev/marketplace-admin', ( pages: WcAdminPage[] ) => [
	...pages,
	...buildAdminPages( readManifest() ),
] );
